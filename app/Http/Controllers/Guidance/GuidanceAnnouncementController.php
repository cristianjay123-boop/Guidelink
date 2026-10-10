<?php

namespace App\Http\Controllers\Guidance;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\GuidanceAnnouncement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuidanceAnnouncementController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensurePublisher($request);

        return Inertia::render('guidance/dashboard', [
            'posts' => $this->postsFor($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensurePublisher($request);

        $validated = $request->validate([
            'type' => ['required', 'in:announcement,event,program'],
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:10000'],
            'event_at' => ['nullable', 'date'],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $imagePath = $request->file('image')->store('guidance-announcements', 'local');
        abort_if($imagePath === false, 500, 'Unable to save the announcement image.');

        GuidanceAnnouncement::query()->create([
            'guidance_id' => $request->user()->id,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'body' => $validated['body'],
            'event_at' => $validated['event_at'] ?? null,
            'image_path' => $imagePath,
        ]);

        return to_route($this->dashboardRoute($request))->with('status', 'Post published.');
    }

    public function update(Request $request, GuidanceAnnouncement $announcement): RedirectResponse
    {
        $this->ensureOwnAnnouncement($request, $announcement);

        $validated = $request->validate([
            'type' => ['required', 'in:announcement,event,program'],
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:10000'],
            'event_at' => ['nullable', 'date'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $oldImagePath = $announcement->image_path;
        $imagePath = $oldImagePath;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('guidance-announcements', 'local');
            abort_if($imagePath === false, 500, 'Unable to save the announcement image.');
        }

        $announcement->update([
            'type' => $validated['type'],
            'title' => $validated['title'],
            'body' => $validated['body'],
            'event_at' => $validated['event_at'] ?? null,
            'image_path' => $imagePath,
        ]);

        if ($imagePath !== $oldImagePath) {
            Storage::disk('local')->delete($oldImagePath);
        }

        return to_route($this->dashboardRoute($request))->with('status', 'Post updated.');
    }

    public function destroy(Request $request, GuidanceAnnouncement $announcement): RedirectResponse
    {
        $this->ensureOwnAnnouncement($request, $announcement);

        $imagePath = $announcement->image_path;
        $announcement->delete();
        Storage::disk('local')->delete($imagePath);

        return to_route($this->dashboardRoute($request))->with('status', 'Post deleted.');
    }

    public function image(Request $request, GuidanceAnnouncement $announcement): StreamedResponse
    {
        if (! in_array($request->user()->role, [UserRole::Student, UserRole::Teacher], true)) {
            $this->ensurePublisher($request);
        }

        abort_unless(Storage::disk('local')->exists($announcement->image_path), 404);

        return Storage::disk('local')->response($announcement->image_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function ensurePublisher(Request $request): void
    {
        abort_unless(in_array($request->user()->role, [
            UserRole::Guidance,
            UserRole::Dean,
        ], true), 403);
    }

    private function ensureOwnAnnouncement(Request $request, GuidanceAnnouncement $announcement): void
    {
        $this->ensurePublisher($request);
        abort_unless($announcement->guidance_id === $request->user()->id, 403);
    }

    private function dashboardRoute(Request $request): string
    {
        return $request->user()->role === UserRole::Guidance
            ? 'guidance.dashboard'
            : 'dashboard';
    }

    /**
     * @return array<int, array{id: int, type: string, title: string, body: string, eventAt: string|null, imageUrl: string, authorName: string, authorRole: string, canManage: bool, createdAt: string}>
     */
    private function postsFor(Request $request): array
    {
        return GuidanceAnnouncement::query()
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
                'canManage' => $announcement->guidance_id === $request->user()->id,
                'createdAt' => $announcement->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
