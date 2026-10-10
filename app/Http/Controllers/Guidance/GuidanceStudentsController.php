<?php

namespace App\Http\Controllers\Guidance;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuidanceStudentsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()?->role === UserRole::Guidance, 403);

        $students = User::query()
            ->where('role', UserRole::Student)
            ->where('must_change_password', false)
            ->with('studentProfile')
            ->orderBy('name')
            ->get()
            ->map(function (User $student) {
                $academicDetails = $student->studentProfile?->academicDetails() ?? [
                    'course' => null,
                    'yearLevel' => null,
                ];

                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'studentNumber' => $student->student_number ?? $student->login_id,
                    'course' => $academicDetails['course'],
                    'yearLevel' => $academicDetails['yearLevel'],
                    'hasProfile' => $student->studentProfile !== null,
                ];
            })
            ->values();

        return Inertia::render('guidance/students', [
            'students' => $students,
        ]);
    }
}
