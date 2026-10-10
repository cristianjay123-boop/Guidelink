<?php

namespace App\Http\Controllers\Guidance;

use App\Enums\UserRole;
use App\Enums\CounselingRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\CounselingRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuidanceStudentProfileController extends Controller
{
    public function show(Request $request, User $student): Response
    {
        $this->ensureGuidanceStudent($request, $student);

        $profile = $student->studentProfile;
        $counselingLogs = CounselingRequest::query()
            ->with('slot')
            ->where('guidance_id', $request->user()->id)
            ->where('student_id', $student->id)
            ->whereIn('status', [
                CounselingRequestStatus::Completed,
                CounselingRequestStatus::Unsuccessful,
            ])
            ->latest()
            ->get()
            ->map(fn (CounselingRequest $counselingRequest) => [
                'id' => $counselingRequest->id,
                'semester' => $counselingRequest->semester,
                'status' => $counselingRequest->status->label(),
                'sessionDate' => $counselingRequest->slot?->starts_at?->format('M j, Y g:i A'),
                'reason' => $counselingRequest->message,
                'notes' => $counselingRequest->guidance_notes,
            ])
            ->values();
        $personal = $profile?->personal ?? [];
        $academicDetails = $profile?->academicDetails() ?? [
            'course' => null,
            'yearLevel' => null,
        ];
        $personal['last_name'] ??= $profile?->last_name;
        $personal['first_name'] ??= $profile?->first_name;
        $personal['middle_name'] ??= $profile?->middle_name;
        $personal['course'] ??= $academicDetails['course'];
        $personal['year_level'] ??= $academicDetails['yearLevel'];
        $personal = [
            'name' => $student->name,
            'first_name' => $personal['first_name'] ?? null,
            'middle_name' => $personal['middle_name'] ?? null,
            'last_name' => $personal['last_name'] ?? null,
            ...array_diff_key($personal, array_flip([
                'first_name',
                'middle_name',
                'last_name',
            ])),
        ];

        return Inertia::render('guidance/student-profile', [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'studentNumber' => $student->student_number,
            ],
            'hasProfile' => $profile !== null,
            'counselingLogs' => $counselingLogs,
            'profile' => [
                'personal' => $personal,
                'emergencyContact' => $profile?->emergency_contact ?? [],
                'education' => $profile?->education ?? [],
                'health' => $profile?->health ?? [],
                'interests' => $profile?->interests ?? [],
                'family' => $profile?->family ?? [],
                'socioeconomic' => $profile?->socioeconomic ?? [],
                'psychologicalTests' => $profile?->psychological_tests ?? [],
            ],
        ]);
    }

    public function storePsychologicalTest(Request $request, User $student): RedirectResponse
    {
        $this->ensureGuidanceStudent($request, $student);

        $validated = $request->validate([
            'date_tested' => ['required', 'date', 'before_or_equal:today'],
            'name' => ['required', 'string', 'max:255'],
            'scores_description' => ['required', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);

        $profile = $student->studentProfile()->firstOrCreate();
        $tests = $profile->psychological_tests ?? [];
        $tests[] = [
            'date_tested' => $validated['date_tested'],
            'name' => trim($validated['name']),
            'scores_description' => trim($validated['scores_description']),
            'remarks' => trim($validated['remarks'] ?? ''),
        ];
        $profile->psychological_tests = $tests;
        $profile->save();

        return to_route('guidance.students.profile', $student)
            ->with('status', 'Psychological test result added.');
    }

    private function ensureGuidanceStudent(Request $request, User $student): void
    {
        abort_unless($request->user()?->role === UserRole::Guidance, 403);
        abort_unless(
            $student->role === UserRole::Student && ! $student->must_change_password,
            404,
        );
    }
}
