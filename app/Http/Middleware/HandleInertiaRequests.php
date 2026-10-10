<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\GuidanceAnnouncement;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $userName = $user?->name;

        if ($user?->role === UserRole::Student) {
            $studentProfile = $user->studentProfile;
            $personal = $studentProfile?->personal ?? [];
            $studentInformationName = collect([
                $studentProfile?->first_name ?? ($personal['first_name'] ?? null),
                $studentProfile?->middle_name ?? ($personal['middle_name'] ?? null),
                $studentProfile?->last_name ?? ($personal['last_name'] ?? null),
            ])
                ->map(fn (?string $name) => trim((string) $name))
                ->filter()
                ->implode(' ');

            if ($studentInformationName !== '') {
                $userName = $studentInformationName;
            }
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $userName,
                    'email' => $user->email,
                    'login_id' => $user->login_id,
                    'role' => $user->role->value,
                    'avatar' => $user->role === UserRole::Student
                        && $user->studentProfile()->whereNotNull('photo_path')->exists()
                            ? route('student-profile.photo')
                            : null,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ] : null,
            ],
            'calendarEvents' => in_array($user?->role, [
                UserRole::Student,
                UserRole::Teacher,
                UserRole::Dean,
                UserRole::Guidance,
            ], true)
                ? GuidanceAnnouncement::query()
                    ->whereIn('type', ['event', 'program'])
                    ->whereNotNull('event_at')
                    ->orderBy('event_at')
                    ->get(['id', 'title', 'body', 'event_at'])
                    ->map(fn (GuidanceAnnouncement $event) => [
                        'id' => $event->id,
                        'title' => $event->title,
                        'body' => $event->body,
                        'date' => $event->event_at->format('Y-m-d'),
                        'time' => $event->event_at->format('g:i A'),
                    ])
                    ->all()
                : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
