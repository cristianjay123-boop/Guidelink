<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\EvaluationForm;
use App\Models\GuidanceAnnouncement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user?->role === UserRole::Guidance) {
            return to_route('guidance.dashboard');
        }

        $isSuperadmin = $user?->role === UserRole::Superadmin;
        $openEvaluationCount = $user?->role === UserRole::Student
            ? EvaluationForm::query()
                ->availableToStudent($user->id)
                ->where('is_published', true)
                ->whereDoesntHave('responses', fn ($query) => $query->where('student_id', $user->id))
                ->count()
            : 0;
        $announcements = in_array($user?->role, [UserRole::Student, UserRole::Teacher], true)
            ? GuidanceAnnouncement::query()
                ->with('author:id,name,role')
                ->latest()
                ->orderByDesc('id')
                ->get()
                ->map(fn (GuidanceAnnouncement $announcement) => [
                    'id' => $announcement->id,
                    'type' => $announcement->type,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'eventAt' => $announcement->event_at?->toIso8601String(),
                    'imageUrl' => route('guidance.announcements.image', $announcement),
                    'authorName' => $announcement->author->name,
                    'authorRole' => $announcement->author->role->value,
                    'createdAt' => $announcement->created_at->toIso8601String(),
                ])
                ->all()
            : [];
        $myPosts = $user?->role === UserRole::Dean
            ? GuidanceAnnouncement::query()
                ->with('author:id,name,role')
                ->latest()
                ->orderByDesc('id')
                ->get()
                ->map(fn (GuidanceAnnouncement $announcement) => [
                    'id' => $announcement->id,
                    'type' => $announcement->type,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'eventAt' => $announcement->event_at?->toIso8601String(),
                    'imageUrl' => route('guidance.announcements.image', $announcement),
                    'authorName' => $announcement->author->name,
                    'authorRole' => $announcement->author->role->value,
                    'canManage' => $announcement->guidance_id === $user->id,
                    'createdAt' => $announcement->created_at->toIso8601String(),
                ])
                ->all()
            : [];

        return Inertia::render('dashboard', [
            'announcements' => $announcements,
            'myPosts' => $myPosts,
            'openEvaluationCount' => $openEvaluationCount,
            'superadminOverview' => $isSuperadmin ? [
                'totalAccounts' => User::query()->count(),
                'temporaryPasswordCount' => User::query()->where('must_change_password', true)->count(),
                'roleCounts' => collect(UserRole::cases())
                    ->map(fn (UserRole $role) => [
                        'role' => $role->value,
                        'label' => $role->label(),
                        'count' => User::query()->where('role', $role->value)->count(),
                    ])
                    ->all(),
                'recentAccounts' => User::query()
                    ->latest('id')
                    ->limit(8)
                    ->get(['id', 'name', 'login_id', 'role', 'must_change_password'])
                    ->map(fn (User $user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'loginId' => $user->login_id,
                        'role' => $user->role->value,
                        'mustChangePassword' => $user->must_change_password,
                    ])
                    ->all(),
            ] : null,
        ]);
    }
}
