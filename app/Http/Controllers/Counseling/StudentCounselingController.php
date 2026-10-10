<?php

namespace App\Http\Controllers\Counseling;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CounselingRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentCounselingController extends Controller
{
    public function index(Request $request): Response
    {
        $student = $this->ensureStudent($request);

        $requests = CounselingRequest::query()
            ->with(['guidance', 'slot'])
            ->where('student_id', $student->id)
            ->latest()
            ->get()
            ->map(function (CounselingRequest $counselingRequest) {
                return [
                    'id' => $counselingRequest->id,
                    'guidanceId' => $counselingRequest->guidance_id,
                    'guidanceName' => $counselingRequest->guidance->name,
                    'semester' => $counselingRequest->semester,
                    'message' => $counselingRequest->message,
                    'status' => $counselingRequest->status->value,
                    'statusLabel' => $counselingRequest->status->label(),
                    'slotLabel' => $counselingRequest->slot
                        ? $counselingRequest->slot->starts_at->format('D, M j, Y g:i A').' – '.$counselingRequest->slot->ends_at->format('g:i A')
                        : null,
                ];
            });

        return Inertia::render('student/counseling', [
            'requests' => $requests,
        ]);
    }

    private function ensureStudent(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->role === UserRole::Student, 403);

        return $user;
    }
}
