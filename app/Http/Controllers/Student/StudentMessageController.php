<?php

namespace App\Http\Controllers\Student;

use App\Enums\CounselingRequestStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\CounselingRequest;
use App\Models\CounselingSlot;
use App\Models\DirectMessage;
use App\Models\GuidanceAnnouncement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StudentMessageController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->inbox($request);
    }

    public function widgetIndex(Request $request): JsonResponse
    {
        $viewer = $this->messagingUser($request);

        return response()->json([
            'currentUserId' => $viewer->id,
            'profiles' => $this->studentProfiles($viewer),
            'conversations' => $this->accessibleConversationsQuery($viewer)
                ->with([
                    'participants:id,name,role',
                    'userOne:id,name,role',
                    'userTwo:id,name,role',
                    'latestMessage.sender:id,name',
                ])
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Conversation $conversation) => $this->conversationData($conversation, $viewer))
                ->values(),
        ]);
    }

    public function notificationSummary(Request $request): JsonResponse
    {
        $viewer = $this->messagingUser($request);
        $cursor = $this->notificationCursor($viewer);
        $messages = $this->unreadMessagesQuery($viewer, $cursor->message_read_through_id);
        $posts = GuidanceAnnouncement::query()
            ->where('id', '>', $cursor->post_read_through_id)
            ->where('guidance_id', '!=', $viewer->id);

        $latestMessages = (clone $messages)
            ->with(['sender:id,name', 'conversation'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (DirectMessage $message) => [
                'key' => 'message-'.$message->id,
                'type' => 'message',
                'title' => $message->sender->name,
                'body' => $message->body,
                'url' => route('student.messages.conversations.show', $message->conversation_id),
                'createdAt' => $message->created_at?->toIso8601String(),
            ]);

        $latestPosts = (clone $posts)
            ->with('author:id,name')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (GuidanceAnnouncement $announcement) => [
                'key' => 'post-'.$announcement->id,
                'type' => 'post',
                'title' => $announcement->title,
                'body' => $announcement->body,
                'author' => $announcement->author->name,
                'url' => $viewer->role === UserRole::Guidance
                    ? route('guidance.dashboard')
                    : route('dashboard'),
                'createdAt' => $announcement->created_at?->toIso8601String(),
            ]);

        $latest = $latestMessages
            ->concat($latestPosts)
            ->sortByDesc('createdAt')
            ->take(10)
            ->values();
        $messagesCount = $messages->count();
        $postsCount = $posts->count();

        return response()->json([
            'messagesCount' => $messagesCount,
            'postsCount' => $postsCount,
            'unreadCount' => $messagesCount + $postsCount,
            'latest' => $latest,
        ]);
    }

    public function markNotificationsRead(Request $request): JsonResponse
    {
        $viewer = $this->messagingUser($request);
        $validated = $request->validate([
            'type' => ['required', 'in:messages,posts,all'],
        ]);
        $cursor = $this->notificationCursor($viewer);
        $updates = ['updated_at' => now()];

        if (in_array($validated['type'], ['messages', 'all'], true)) {
            $updates['message_read_through_id'] = max(
                $cursor->message_read_through_id,
                (int) ($this->unreadMessagesQuery($viewer, $cursor->message_read_through_id)->max('id') ?? 0),
            );
        }

        if (in_array($validated['type'], ['posts', 'all'], true)) {
            $updates['post_read_through_id'] = (int) (GuidanceAnnouncement::query()->max('id') ?? 0);
        }

        DB::table('user_notification_cursors')
            ->where('user_id', $cursor->user_id)
            ->update($updates);

        return $this->notificationSummary($request);
    }

    public function showWidgetConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $viewer = $this->messagingUser($request);
        $this->ensureConversationAccess($viewer, $conversation);
        $conversation->load([
            'participants:id,name,role',
            'userOne:id,name,role',
            'userTwo:id,name,role',
            'latestMessage.sender:id,name',
        ]);

        return response()->json([
            'conversation' => $this->activeConversationData($conversation, $viewer),
            'counseling' => $this->counselingData($viewer, $conversation, null),
        ]);
    }

    public function showWidgetUser(Request $request, User $user): JsonResponse
    {
        $viewer = $this->messagingUser($request);
        $this->ensureMessagingTarget($viewer, $user);

        $conversation = $this->findDirectConversation($viewer, $user)
            ?? $this->createDirectConversation($viewer, $user);
        $conversation->load([
            'participants:id,name,role',
            'userOne:id,name,role',
            'userTwo:id,name,role',
            'latestMessage.sender:id,name',
        ]);

        return response()->json([
            'conversation' => $this->activeConversationData($conversation, $viewer),
            'counseling' => $this->counselingData($viewer, $conversation, null),
        ]);
    }

    public function storeWidgetMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $sender = $this->messagingUser($request);
        $this->ensureConversationAccess($sender, $conversation);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => $validated['body'],
        ]);
        $conversation->touch();
        $conversation->load([
            'participants:id,name,role',
            'userOne:id,name,role',
            'userTwo:id,name,role',
            'latestMessage.sender:id,name',
        ]);

        return response()->json([
            'conversation' => $this->activeConversationData($conversation, $sender),
            'counseling' => $this->counselingData($sender, $conversation, null),
            'conversations' => $this->accessibleConversations($sender)
                ->map(fn (Conversation $item) => $this->conversationData($item, $sender))
                ->values(),
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        $viewer = $this->messagingUser($request);
        $this->ensureMessagingTarget($viewer, $user);
        $conversation = $this->findDirectConversation($viewer, $user);

        if ($conversation !== null) {
            $this->ensureConversationAccess($viewer, $conversation);
        }

        return $this->inbox($request, $conversation, $user);
    }

    public function showConversation(Request $request, Conversation $conversation): Response
    {
        $viewer = $this->messagingUser($request);
        $this->ensureConversationAccess($viewer, $conversation);

        return $this->inbox($request, $conversation->load('participants:id,name,role'));
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        $sender = $this->messagingUser($request);
        $this->ensureMessagingTarget($sender, $user);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $conversation = DB::transaction(function () use ($sender, $user, $validated) {
            $conversation = $this->findDirectConversation($sender, $user)
                ?? $this->createDirectConversation($sender, $user);

            $conversation->messages()->create([
                'sender_id' => $sender->id,
                'body' => $validated['body'],
            ]);
            $conversation->touch();

            return $conversation;
        });

        return to_route('student.messages.show', $user);
    }

    public function createGroup(Request $request): RedirectResponse
    {
        $creator = $this->messagingUser($request);
        abort_unless($creator->role === UserRole::Guidance, 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'participant_ids' => ['required', 'array', 'min:2', 'max:50'],
            'participant_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $participantIds = [];
        foreach ($validated['participant_ids'] as $id) {
            if ((! is_int($id) && ! is_string($id)) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw ValidationException::withMessages([
                    'participant_ids' => 'Choose valid student profiles.',
                ]);
            }

            $participantId = (int) $id;
            if ($participantId !== $creator->id) {
                $participantIds[$participantId] = $participantId;
            }
        }
        $participantIds = array_values($participantIds);

        $participants = User::query()
            ->whereIn('id', $participantIds)
            ->where('role', UserRole::Student)
            ->where('must_change_password', false)
            ->get(['id']);

        if ($participants->count() !== count($participantIds)) {
            throw ValidationException::withMessages([
                'participant_ids' => 'Choose active student accounts.',
            ]);
        }

        $conversation = DB::transaction(function () use ($creator, $validated, $participants) {
            $conversation = Conversation::query()->create([
                'type' => 'group',
                'title' => trim($validated['title']),
                'created_by' => $creator->id,
            ]);
            $conversation->participants()->sync([
                $creator->id,
                ...$participants->modelKeys(),
            ]);

            return $conversation;
        });

        return to_route('student.messages.conversations.show', $conversation);
    }

    public function storeConversationMessage(Request $request, Conversation $conversation): RedirectResponse
    {
        $sender = $this->messagingUser($request);
        $this->ensureConversationAccess($sender, $conversation);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => $validated['body'],
        ]);
        $conversation->touch();

        return to_route('student.messages.conversations.show', $conversation);
    }

    private function inbox(
        Request $request,
        ?Conversation $activeConversation = null,
        ?User $activeProfile = null,
    ): Response {
        $viewer = $this->messagingUser($request);
        $conversations = $this->accessibleConversations($viewer);

        if ($activeConversation !== null) {
            $activeConversation = $conversations->firstWhere('id', $activeConversation->id);
            abort_if($activeConversation === null, 404);
        }

        $profiles = $this->studentProfiles($viewer);

        return Inertia::render('student/messages/index', [
            'currentUserId' => $viewer->id,
            'canCreateGroups' => $viewer->role === UserRole::Guidance,
            'profiles' => $profiles,
            'conversations' => $conversations->map(
                fn (Conversation $conversation) => $this->conversationData($conversation, $viewer),
            )->values(),
            'activeConversation' => $activeConversation
                ? $this->activeConversationData($activeConversation, $viewer)
                : null,
            'activeProfile' => $activeProfile
                ? [
                    'id' => $activeProfile->id,
                    'name' => $activeProfile->name,
                    'role' => $activeProfile->role->value,
                    ...($activeProfile->studentProfile?->academicDetails() ?? [
                        'course' => null,
                        'yearLevel' => null,
                    ]),
                ]
                : null,
            'counseling' => $this->counselingData($viewer, $activeConversation, $activeProfile),
        ]);
    }

    /**
     * @return array{guidanceId: int|null, studentId: int|null, canRequest: bool, requests: array<int, array<string, mixed>>}
     */
    private function counselingData(
        User $viewer,
        ?Conversation $conversation,
        ?User $activeProfile,
    ): array {
        $otherUser = $activeProfile;

        if ($otherUser === null && $conversation?->type === 'direct') {
            $otherUser = $conversation->participants->first(
                fn (User $participant) => $participant->id !== $viewer->id,
            ) ?? ($conversation->user_one_id === $viewer->id
                ? $conversation->userTwo
                : $conversation->userOne);
        }

        if ($otherUser === null) {
            return ['guidanceId' => null, 'canRequest' => false, 'requests' => []];
        }

        $student = $viewer->role === UserRole::Student ? $viewer : $otherUser;
        $guidance = $viewer->role === UserRole::Guidance ? $viewer : $otherUser;

        if ($student->role !== UserRole::Student || $guidance->role !== UserRole::Guidance) {
            return ['guidanceId' => null, 'canRequest' => false, 'requests' => []];
        }

        $requests = CounselingRequest::query()
            ->with('slot')
            ->where('student_id', $student->id)
            ->where('guidance_id', $guidance->id)
            ->latest()
            ->get()
            ->map(function (CounselingRequest $request) use ($guidance, $viewer) {
                $availableSlots = $request->status === CounselingRequestStatus::Accepted
                    ? CounselingSlot::query()
                        ->where('guidance_id', $guidance->id)
                        ->where('semester', $request->semester)
                        ->whereNull('counseling_request_id')
                        ->where('starts_at', '>', now())
                        ->orderBy('starts_at')
                        ->get(['id', 'starts_at', 'ends_at'])
                        ->map(fn (CounselingSlot $slot) => [
                            'id' => $slot->id,
                            'label' => $slot->starts_at->format('D, M j, Y g:i A').' – '.$slot->ends_at->format('g:i A'),
                        ])
                        ->all()
                    : [];

                return [
                    'id' => $request->id,
                    'semester' => $request->semester,
                    'message' => $request->message,
                    'status' => $request->status->value,
                    'statusLabel' => $request->status->label(),
                    'declineReason' => $request->decline_reason,
                    ...($viewer->role === UserRole::Guidance
                        ? ['guidanceNotes' => $request->guidance_notes]
                        : []),
                    'slotLabel' => $request->slot
                        ? $request->slot->starts_at->format('D, M j, Y g:i A').' – '.$request->slot->ends_at->format('g:i A')
                        : null,
                    'canComplete' => $request->status === CounselingRequestStatus::Scheduled
                        && $request->slot?->starts_at?->isPast() === true,
                    'availableSlots' => $availableSlots,
                ];
            })
            ->all();

        $hasActiveRequest = CounselingRequest::query()
            ->where('student_id', $student->id)
            ->where('guidance_id', $guidance->id)
            ->whereIn('status', [
                CounselingRequestStatus::Pending->value,
                CounselingRequestStatus::Accepted->value,
                CounselingRequestStatus::Scheduled->value,
            ])
            ->exists();

        return [
            'guidanceId' => $guidance->id,
            'studentId' => $student->id,
            'canRequest' => ! $hasActiveRequest && in_array($viewer->role, [UserRole::Student, UserRole::Guidance], true),
            'requests' => $requests,
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Conversation>
     */
    private function accessibleConversations(User $viewer): \Illuminate\Database\Eloquent\Collection
    {
        return $this->accessibleConversationsQuery($viewer)
            ->with([
                'participants:id,name,role',
                'userOne:id,name,role',
                'userTwo:id,name,role',
                'latestMessage.sender:id,name',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Builder<Conversation>
     */
    private function accessibleConversationsQuery(User $viewer): Builder
    {
        return Conversation::query()
            ->where(function (Builder $query) use ($viewer) {
                $query
                    ->where('type', 'all_students_guidance')
                    ->orWhere(fn (Builder $group) => $group
                        ->where('type', 'group')
                        ->whereHas('participants', fn (Builder $participants) => $participants->whereKey($viewer->id)))
                    ->orWhere(function (Builder $direct) use ($viewer) {
                        $direct->where('type', 'direct');

                        if ($viewer->role === UserRole::Student) {
                            $direct->where(function (Builder $pair) use ($viewer) {
                                $pair
                                    ->where(fn (Builder $first) => $first
                                        ->where('user_one_id', $viewer->id)
                                        ->whereHas('userTwo', fn (Builder $other) => $other->where('role', UserRole::Guidance)))
                                    ->orWhere(fn (Builder $second) => $second
                                        ->where('user_two_id', $viewer->id)
                                        ->whereHas('userOne', fn (Builder $other) => $other->where('role', UserRole::Guidance)));
                            });
                        } else {
                            $direct->where(function (Builder $pair) use ($viewer) {
                                $pair
                                    ->where('user_one_id', $viewer->id)
                                    ->orWhere('user_two_id', $viewer->id);
                            });
                        }
                    });
            });
    }

    private function notificationCursor(User $viewer): \stdClass
    {
        DB::table('user_notification_cursors')->insertOrIgnore([
            'user_id' => $viewer->id,
            'message_read_through_id' => (int) (
                DirectMessage::query()
                    ->where('created_at', '<', $viewer->created_at)
                    ->max('id') ?? 0
            ),
            'post_read_through_id' => (int) (
                GuidanceAnnouncement::query()
                    ->where('created_at', '<', $viewer->created_at)
                    ->max('id') ?? 0
            ),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cursor = DB::table('user_notification_cursors')
            ->where('user_id', $viewer->id)
            ->first();

        if ($cursor === null) {
            throw new \RuntimeException('Unable to initialize notification cursor.');
        }

        return $cursor;
    }

    /**
     * @return Builder<DirectMessage>
     */
    private function unreadMessagesQuery(User $viewer, int $readThroughId): Builder
    {
        return DirectMessage::query()
            ->where('id', '>', $readThroughId)
            ->where('sender_id', '!=', $viewer->id)
            ->whereHas('conversation', fn (Builder $query) => $query->whereIn(
                'conversations.id',
                $this->accessibleConversationsQuery($viewer)->select('conversations.id'),
            ));
    }

    /**
     * @return Collection<int, array{id: int, name: string, course: string|null, yearLevel: string|null}>
     */
    private function studentProfiles(User $viewer)
    {
        $targetRole = $viewer->role === UserRole::Guidance
            ? UserRole::Student
            : UserRole::Guidance;

        return User::query()
            ->where('role', $targetRole)
            ->where('must_change_password', false)
            ->whereKeyNot($viewer->id)
            ->with('studentProfile')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                ...($user->studentProfile?->academicDetails() ?? [
                    'course' => null,
                    'yearLevel' => null,
                ]),
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationData(Conversation $conversation, User $viewer): array
    {
        $participants = $this->conversationParticipants($conversation, $viewer);
        $isDirect = $conversation->type === 'direct';
        $otherParticipant = $isDirect
            ? ($conversation->participants->first(fn (User $participant) => $participant->id !== $viewer->id)
                ?? ($conversation->user_one_id === $viewer->id
                    ? $conversation->userTwo
                    : $conversation->userOne))
            : null;
        $name = match ($conversation->type) {
            'all_students_guidance' => $conversation->title,
            'group' => $conversation->title,
            default => $otherParticipant->name ?? 'Conversation',
        };

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'name' => $name,
            'participants' => $participants,
            'mentionCandidates' => $this->mentionCandidates($conversation, $viewer),
            'lastMessage' => $conversation->latestMessage?->body,
            'updatedAt' => $conversation->latestMessage?->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{id: int, name: string, role: string}>
     */
    private function mentionCandidates(Conversation $conversation, User $viewer): array
    {
        if ($conversation->type === 'all_students_guidance') {
            return User::query()
                ->whereIn('role', [UserRole::Student, UserRole::Guidance])
                ->where('must_change_password', false)
                ->whereKeyNot($viewer->id)
                ->orderBy('name')
                ->get(['id', 'name', 'role'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role->value,
                ])
                ->all();
        }

        $participants = $conversation->participants;
        if ($conversation->type === 'direct' && $participants->isEmpty()) {
            $participants = collect([$conversation->userOne, $conversation->userTwo])
                ->filter()
                ->values();
        }

        return $participants
            ->reject(fn (User $participant) => $participant->is($viewer))
            ->map(fn (User $participant) => [
                'id' => $participant->id,
                'name' => $participant->name,
                'role' => $participant->role->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function activeConversationData(Conversation $conversation, User $viewer): array
    {
        $data = $this->conversationData($conversation, $viewer);

        return [
            ...$data,
            'messages' => $conversation->messages()
                ->with('sender:id,name')
                ->orderBy('id')
                ->get()
                ->map(fn (DirectMessage $message) => [
                    'id' => $message->id,
                    'senderId' => $message->sender_id,
                    'senderName' => $message->sender->name,
                    'body' => $message->body,
                    'sentAt' => $message->created_at?->toIso8601String(),
                ])
                ->values(),
        ];
    }

    /**
     * @return list<array{id: int, name: string, role: string}>
     */
    private function conversationParticipants(Conversation $conversation, User $viewer): array
    {
        if ($conversation->type === 'all_students_guidance') {
            return [
                ['id' => 0, 'name' => 'All students and guidance', 'role' => 'group'],
            ];
        }

        $participants = $conversation->participants;

        if ($conversation->type === 'direct' && $participants->isEmpty()) {
            $participants = collect([$conversation->userOne, $conversation->userTwo])
                ->filter()
                ->values();
        }

        return array_values($participants
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->id === $viewer->id ? 'You' : $user->name,
                'role' => $user->role->value,
            ])
            ->all());
    }

    private function messagingUser(Request $request): User
    {
        $user = $request->user();
        abort_unless(in_array($user?->role, [UserRole::Student, UserRole::Guidance], true), 403);

        return $user;
    }

    private function ensureMessagingTarget(User $sender, User $recipient): void
    {
        abort_if($sender->is($recipient), 404);
        $targetIsAvailable = match ($sender->role) {
            UserRole::Guidance => $recipient->role === UserRole::Student
                && ! $recipient->must_change_password,
            UserRole::Student => $recipient->role === UserRole::Guidance,
            default => false,
        };

        abort_unless($targetIsAvailable, 404);
    }

    private function ensureConversationAccess(User $user, Conversation $conversation): void
    {
        if ($conversation->type === 'all_students_guidance') {
            abort_unless(in_array($user->role, [UserRole::Student, UserRole::Guidance], true), 404);

            return;
        }

        $isParticipant = $conversation->participants()->whereKey($user->id)->exists();
        $isLegacyDirectParticipant = $conversation->type === 'direct'
            && in_array($user->id, [$conversation->user_one_id, $conversation->user_two_id], true);

        if ($conversation->type === 'direct' && $user->role === UserRole::Student) {
            $otherUserId = $conversation->user_one_id === $user->id
                ? $conversation->user_two_id
                : $conversation->user_one_id;
            $isGuidanceConversation = User::query()
                ->whereKey($otherUserId)
                ->where('role', UserRole::Guidance)
                ->exists();

            abort_unless(($isParticipant || $isLegacyDirectParticipant) && $isGuidanceConversation, 404);

            return;
        }

        abort_unless($isParticipant || $isLegacyDirectParticipant, 404);
    }

    private function findDirectConversation(User $firstUser, User $secondUser): ?Conversation
    {
        [$userOneId, $userTwoId] = $this->orderedUserIds($firstUser, $secondUser);

        return Conversation::query()
            ->where('type', 'direct')
            ->where('user_one_id', $userOneId)
            ->where('user_two_id', $userTwoId)
            ->with('participants:id,name,role')
            ->first();
    }

    private function createDirectConversation(User $firstUser, User $secondUser): Conversation
    {
        [$userOneId, $userTwoId] = $this->orderedUserIds($firstUser, $secondUser);
        $conversation = Conversation::query()->create([
            'type' => 'direct',
            'user_one_id' => $userOneId,
            'user_two_id' => $userTwoId,
        ]);
        $conversation->participants()->sync([$userOneId, $userTwoId]);

        return $conversation;
    }

    /**
     * @return array{int, int}
     */
    private function orderedUserIds(User $firstUser, User $secondUser): array
    {
        return $firstUser->id < $secondUser->id
            ? [$firstUser->id, $secondUser->id]
            : [$secondUser->id, $firstUser->id];
    }
}
