<?php

namespace App\Http\Controllers\Guidance;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\StudentSubjectEnrollment;
use App\Models\TeacherSubjectLoad;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuidanceClasslistController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()->role === UserRole::Guidance, 403);

        $teachers = User::query()
            ->where('role', UserRole::Teacher->value)
            ->orderBy('name')
            ->get(['id', 'name']);

        $enrollments = StudentSubjectEnrollment::query()
            ->with([
                'student:id,name,student_number,login_id',
                'student.studentProfile',
            ])
            ->orderBy('teacher_id')
            ->orderBy('subject')
            ->get();

        $subjectLoads = TeacherSubjectLoad::query()
            ->where('course_code', '!=', '')
            ->orderBy('course_code')
            ->orderBy('academic_year')
            ->orderBy('semester')
            ->get();

        $teachersWithSubjects = $teachers
            ->map(function (User $teacher) use ($enrollments, $subjectLoads) {
                $subjects = $subjectLoads
                    ->where('teacher_id', $teacher->id)
                    ->groupBy(fn (TeacherSubjectLoad $load) => mb_strtolower(trim($load->course_code)))
                    ->map(function ($loads, string $courseCodeKey) use ($enrollments, $teacher) {
                        $load = $loads->first();
                        $students = $enrollments
                            ->filter(fn (StudentSubjectEnrollment $enrollment) => $enrollment->teacher_id === $teacher->id
                                && mb_strtolower(trim($enrollment->subject)) === $courseCodeKey)
                            ->map(function (StudentSubjectEnrollment $enrollment) {
                                $student = $enrollment->student;

                                return [
                                    'id' => $student->id,
                                    'studentNumber' => $student->student_number ?? $student->login_id,
                                    'name' => $student->name,
                                    'yearLevel' => $student->studentProfile?->academicDetails()['yearLevel'],
                                ];
                            })
                            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                            ->unique('id')
                            ->values()
                            ->all();

                        return [
                            'courseCode' => $load->course_code,
                            'name' => $load->course_code,
                            'descriptiveTitle' => $load->descriptive_title,
                            'courseYears' => $loads->pluck('course_year')->filter()->unique()->values()->all(),
                            'terms' => $loads
                                ->map(fn (TeacherSubjectLoad $termLoad) => [
                                    'semester' => $termLoad->semester,
                                    'academicYear' => $termLoad->academic_year,
                                ])
                                ->unique(fn (array $term) => $term['semester'].'|'.$term['academicYear'])
                                ->values()
                                ->all(),
                            'studentCount' => count($students),
                            'students' => $students,
                        ];
                    })
                    ->sortBy('courseCode', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all();

                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'subjects' => $subjects,
                ];
            })
            ->values()
            ->all();

        $selectedTeacherId = $request->query('teacher_id');
        $selectedSubject = $request->query('subject');
        $selectedClasslist = null;

        if ($selectedTeacherId !== null || $selectedSubject !== null) {
            abort_unless(
                (is_string($selectedTeacherId) || is_int($selectedTeacherId))
                    && ctype_digit((string) $selectedTeacherId)
                    && is_string($selectedSubject)
                    && trim($selectedSubject) !== '',
                404,
            );

            $selectedClasslist = collect($teachersWithSubjects)
                ->firstWhere('id', (int) $selectedTeacherId);

            if ($selectedClasslist) {
                $selectedSubjectData = collect($selectedClasslist['subjects'])
                    ->first(fn (array $subject) => mb_strtolower($subject['courseCode']) === mb_strtolower(trim($selectedSubject)));
                $selectedClasslist = $selectedSubjectData
                    ? [
                        'teacherName' => $selectedClasslist['name'],
                        'subject' => $selectedSubjectData['courseCode'],
                        'descriptiveTitle' => $selectedSubjectData['descriptiveTitle'],
                        'courseYears' => $selectedSubjectData['courseYears'],
                        'terms' => $selectedSubjectData['terms'],
                        'students' => $selectedSubjectData['students'],
                    ]
                    : null;
            }

            abort_unless($selectedClasslist !== null, 404);
        }

        return Inertia::render('guidance/classlists', [
            'teachers' => $teachersWithSubjects,
            'selectedClasslist' => $selectedClasslist,
        ]);
    }
}
