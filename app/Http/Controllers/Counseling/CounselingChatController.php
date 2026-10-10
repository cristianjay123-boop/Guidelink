<?php

namespace App\Http\Controllers\Counseling;

use App\Enums\CounselingRequestStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\CounselingRequest;
use App\Models\CounselingSlot;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CounselingChatController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->role === UserRole::Student || $actor->role === UserRole::Guidance), 403);

        if ($actor->role === UserRole::Student) {
            $validated = $request->validate([
                'guidance_id' => ['required', 'integer', 'exists:users,id'],
                'semester' => ['required', 'string', 'max:120'],
                'message' => ['required', 'string', 'max:2000'],
            ]);

            $guidance = User::query()
                ->whereKey($validated['guidance_id'])
                ->where('role', UserRole::Guidance)
                ->firstOrFail();
            $student = $actor;

            $activeRequestExists = CounselingRequest::query()
                ->where('guidance_id', $guidance->id)
                ->where('student_id', $student->id)
                ->where('semester', $validated['semester'])
                ->whereIn('status', [
                    CounselingRequestStatus::Pending->value,
                    CounselingRequestStatus::Accepted->value,
                    CounselingRequestStatus::Scheduled->value,
                    CounselingRequestStatus::Completed->value,
                ])
                ->exists();

            if ($activeRequestExists) {
                throw ValidationException::withMessages([
                    'semester' => 'You already have a counseling request for this semester.',
                ]);
            }

            DB::transaction(function () use ($guidance, $student, $validated) {
                $counselingRequest = CounselingRequest::query()->create([
                    'guidance_id' => $guidance->id,
                    'student_id' => $student->id,
                    'semester' => trim($validated['semester']),
                    'message' => trim($validated['message']),
                    'status' => CounselingRequestStatus::Pending,
                ]);
                $conversation = $this->directConversation($student, $guidance);
                $this->addConversationMessage(
                    $conversation,
                    $student,
                    'Counseling session requested for '.$counselingRequest->semester.'.',
                );
            });

            return to_route('student.messages.show', $guidance);
        }

        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'semester' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $student = User::query()
            ->whereKey($validated['student_id'])
            ->where('role', UserRole::Student)
            ->firstOrFail();
        $guidance = $actor;

        $activeRequestExists = CounselingRequest::query()
            ->where('guidance_id', $guidance->id)
            ->where('student_id', $student->id)
            ->where('semester', $validated['semester'])
            ->whereIn('status', [
                CounselingRequestStatus::Pending->value,
                CounselingRequestStatus::Accepted->value,
                CounselingRequestStatus::Scheduled->value,
                CounselingRequestStatus::Completed->value,
            ])
            ->exists();

        if ($activeRequestExists) {
            throw ValidationException::withMessages([
                'semester' => 'This student already has an active counseling request for this semester.',
            ]);
        }

        DB::transaction(function () use ($guidance, $student, $validated) {
            $counselingRequest = CounselingRequest::query()->create([
                'guidance_id' => $guidance->id,
                'student_id' => $student->id,
                'semester' => trim($validated['semester']),
                'message' => trim($validated['message']),
                'status' => CounselingRequestStatus::Pending,
            ]);
            $conversation = $this->directConversation($student, $guidance);
            $this->addConversationMessage(
                $conversation,
                $guidance,
                'Counseling session requested for '.$counselingRequest->semester.'.',
            );
        });

        return to_route('student.messages.show', $student);
    }

    public function respond(Request $request, CounselingRequest $counselingRequest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && in_array($user->role, [UserRole::Guidance, UserRole::Student], true), 403);
        abort_unless($counselingRequest->status === CounselingRequestStatus::Pending, 422);

        $validated = $request->validate([
            'answer' => ['required', 'in:yes,no'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $isGuidanceResponse = $user->role === UserRole::Guidance;
        $isStudentResponse = $user->role === UserRole::Student;

        if ($isGuidanceResponse) {
            abort_unless($counselingRequest->guidance_id === $user->id, 404);
        } else {
            abort_unless($counselingRequest->student_id === $user->id, 404);
            if ($validated['answer'] === 'no') {
                $request->validate([
                    'reason' => ['required', 'string', 'min:10', 'max:2000'],
                ]);
            }
        }

        $declineReason = $validated['answer'] === 'no' ? trim((string) ($validated['reason'] ?? '')) : null;
        $counselingRequest->status = $validated['answer'] === 'yes'
            ? CounselingRequestStatus::Accepted
            : CounselingRequestStatus::Closed;
        $counselingRequest->decline_reason = $declineReason !== '' ? $declineReason : null;
        $counselingRequest->save();

        $conversation = $this->directConversation($counselingRequest->student, $counselingRequest->guidance);
        $responseText = $validated['answer'] === 'yes'
            ? 'Your counseling request was accepted. Choose an available time in this chat.'
            : 'Your counseling request was declined. Reason: '.$counselingRequest->decline_reason;

        $this->addConversationMessage(
            $conversation,
            $user,
            $responseText,
        );

        return $isGuidanceResponse
            ? to_route('student.messages.show', $counselingRequest->student)
            : to_route('student.messages.show', $counselingRequest->guidance);
    }

    public function bookSlot(Request $request, CounselingRequest $counselingRequest): RedirectResponse
    {
        $student = $this->ensureRole($request, UserRole::Student);
        abort_unless($counselingRequest->student_id === $student->id, 404);
        abort_unless($counselingRequest->status === CounselingRequestStatus::Accepted, 422);

        $validated = $request->validate([
            'slot_id' => ['required', 'integer', 'exists:counseling_slots,id'],
        ]);

        DB::transaction(function () use ($validated, $counselingRequest) {
            $slot = CounselingSlot::query()
                ->whereKey($validated['slot_id'])
                ->where('guidance_id', $counselingRequest->guidance_id)
                ->where('semester', $counselingRequest->semester)
                ->whereNull('counseling_request_id')
                ->where('starts_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $slot) {
                throw ValidationException::withMessages([
                    'slot_id' => 'That counseling time is no longer available. Select another slot.',
                ]);
            }

            $slot->counseling_request_id = $counselingRequest->id;
            $slot->save();
            $counselingRequest->status = CounselingRequestStatus::Scheduled;
            $counselingRequest->save();
            $conversation = $this->directConversation(
                $counselingRequest->student,
                $counselingRequest->guidance,
            );
            $this->addConversationMessage(
                $conversation,
                $counselingRequest->student,
                'Counseling session booked for '.$slot->starts_at->format('D, M j, Y g:i A').'.',
            );

        });

        return to_route('student.messages.show', $counselingRequest->guidance);
    }

    public function complete(Request $request, CounselingRequest $counselingRequest): RedirectResponse
    {
        $guidance = $this->ensureRole($request, UserRole::Guidance);
        abort_unless($counselingRequest->guidance_id === $guidance->id, 404);
        abort_unless($counselingRequest->status === CounselingRequestStatus::Scheduled, 422);
        abort_unless($counselingRequest->slot?->starts_at?->isPast(), 422);

        $validated = $request->validate([
            'outcome' => ['required', 'in:completed,unsuccessful'],
            'guidance_notes' => [
                'required_if:outcome,completed',
                'nullable',
                'string',
                'min:10',
                'max:5000',
            ],
        ]);

        $outcome = $validated['outcome'] === 'completed'
            ? CounselingRequestStatus::Completed
            : CounselingRequestStatus::Unsuccessful;
        $counselingRequest->update([
            'status' => $outcome,
            'guidance_notes' => $outcome === CounselingRequestStatus::Completed
                ? trim($validated['guidance_notes'])
                : null,
        ]);
        $conversation = $this->directConversation($counselingRequest->student, $guidance);
        $this->addConversationMessage(
            $conversation,
            $guidance,
            $outcome === CounselingRequestStatus::Completed
                ? 'The counseling session has been marked complete.'
                : 'The counseling session was marked not successful because the student was absent.',
        );

        return to_route('student.messages.show', $counselingRequest->student);
    }

    private function directConversation(User $firstUser, User $secondUser): Conversation
    {
        $userOneId = min($firstUser->id, $secondUser->id);
        $userTwoId = max($firstUser->id, $secondUser->id);
        $conversation = Conversation::query()->firstOrCreate([
            'type' => 'direct',
            'user_one_id' => $userOneId,
            'user_two_id' => $userTwoId,
        ]);
        $conversation->participants()->syncWithoutDetaching([$userOneId, $userTwoId]);

        return $conversation;
    }

    private function addConversationMessage(
        Conversation $conversation,
        User $sender,
        string $body,
    ): void {
        $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => $body,
        ]);
        $conversation->touch();
    }

    private function ensureRole(Request $request, UserRole $role): User
    {
        $user = $request->user();
        abort_unless($user && $user->role === $role, 403);

        return $user;
    }
}
