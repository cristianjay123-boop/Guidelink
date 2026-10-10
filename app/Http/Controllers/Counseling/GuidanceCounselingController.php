<?php

namespace App\Http\Controllers\Counseling;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CounselingSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GuidanceCounselingController extends Controller
{
    public function index(Request $request): Response
    {
        $guidance = $this->ensureGuidance($request);
        $month = $request->query('month', now()->format('Y-m'));

        if (! is_string($month) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = now()->format('Y-m');
        }

        $monthStart = CarbonImmutable::parse($month.'-01')->startOfMonth();
        $monthEnd = $monthStart->addMonth();

        $slots = CounselingSlot::query()
            ->with('counselingRequest.student')
            ->where('guidance_id', $guidance->id)
            ->where('starts_at', '>=', $monthStart)
            ->where('starts_at', '<', $monthEnd)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (CounselingSlot $slot) => [
                'id' => $slot->id,
                'semester' => $slot->semester,
                'startsAt' => $slot->starts_at->format('M j, Y g:i A'),
                'endsAt' => $slot->ends_at->format('g:i A'),
                'studentName' => $slot->counselingRequest?->student?->name,
                'isBooked' => $slot->counseling_request_id !== null,
            ]);

        return Inertia::render('guidance/counseling', [
            'slots' => $slots,
            'month' => $month,
        ]);
    }

    public function generateMonthlyTimetable(Request $request): RedirectResponse
    {
        $guidance = $this->ensureGuidance($request);
        $validated = $request->validate([
            'semester' => ['required', 'string', 'max:120'],
            'month' => ['required', 'date_format:Y-m'],
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7'],
            'daily_start' => ['required', 'date_format:H:i'],
            'daily_end' => ['required', 'date_format:H:i'],
            'session_minutes' => ['required', 'integer', 'in:30,45,60'],
        ]);

        if ($validated['daily_end'] <= $validated['daily_start']) {
            throw ValidationException::withMessages([
                'daily_end' => 'The daily end time must be later than the start time.',
            ]);
        }

        $timezone = config('app.timezone');
        $monthStart = CarbonImmutable::parse($validated['month'].'-01', $timezone)->startOfMonth();
        $monthEnd = $monthStart->addMonth();
        $weekdays = array_map('intval', $validated['weekdays']);

        DB::transaction(function () use ($guidance, $validated, $timezone, $monthStart, $monthEnd, $weekdays) {
            $monthSlots = CounselingSlot::query()
                ->where('guidance_id', $guidance->id)
                ->where('starts_at', '>=', $monthStart)
                ->where('starts_at', '<', $monthEnd);

            if ((clone $monthSlots)->whereNotNull('counseling_request_id')->exists()) {
                throw ValidationException::withMessages([
                    'month' => 'This month already has booked sessions, so its timetable cannot be regenerated.',
                ]);
            }

            $monthSlots->delete();
            $slots = [];
            $duration = (int) $validated['session_minutes'];

            for ($day = $monthStart; $day < $monthEnd; $day = $day->addDay()) {
                if (! in_array($day->isoWeekday(), $weekdays, true)) {
                    continue;
                }

                $dayStart = CarbonImmutable::parse($day->format('Y-m-d').' '.$validated['daily_start'], $timezone);
                $dayEnd = CarbonImmutable::parse($day->format('Y-m-d').' '.$validated['daily_end'], $timezone);

                for ($start = $dayStart; $start->addMinutes($duration) <= $dayEnd; $start = $start->addMinutes($duration)) {
                    $end = $start->addMinutes($duration);

                    if ($start <= now()) {
                        continue;
                    }

                    $overlaps = CounselingSlot::query()
                        ->where('guidance_id', $guidance->id)
                        ->where('starts_at', '<', $end)
                        ->where('ends_at', '>', $start)
                        ->exists();

                    if ($overlaps) {
                        throw ValidationException::withMessages([
                            'month' => 'The new timetable overlaps another existing counseling slot.',
                        ]);
                    }

                    $slots[] = [
                        'guidance_id' => $guidance->id,
                        'semester' => $validated['semester'],
                        'starts_at' => $start,
                        'ends_at' => $end,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if ($slots === []) {
                throw ValidationException::withMessages([
                    'month' => 'No future counseling slots fit the selected month and hours.',
                ]);
            }

            CounselingSlot::query()->insert($slots);
        });

        return to_route('guidance.counseling', ['month' => $validated['month']])
            ->with('status', 'Monthly timetable created.');
    }

    private function ensureGuidance(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->role === UserRole::Guidance, 403);

        return $user;
    }
}
