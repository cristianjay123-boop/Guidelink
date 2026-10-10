<?php

namespace App\Http\Controllers\Guidance;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\EvaluationForm;
use App\Models\EvaluationResponse;
use App\Models\StudentSubjectEnrollment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GuidanceEvaluationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensureGuidance($request);

        return Inertia::render('guidance/evaluations/index', [
            'teachers' => User::query()
                ->where('role', UserRole::Teacher->value)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $teacher) => [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                ])
                ->all(),
            'students' => User::query()
                ->where('role', UserRole::Student->value)
                ->orderBy('name')
                ->get(['id', 'name', 'login_id'])
                ->map(fn (User $student) => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'loginId' => $student->login_id,
                ])
                ->all(),
            'enrollments' => StudentSubjectEnrollment::query()
                ->with(['student:id,name,login_id', 'teacher:id,name'])
                ->orderBy('subject')
                ->get()
                ->map(fn (StudentSubjectEnrollment $enrollment) => [
                    'id' => $enrollment->id,
                    'studentName' => $enrollment->student->name,
                    'studentLoginId' => $enrollment->student->login_id,
                    'teacherName' => $enrollment->teacher->name,
                    'subject' => $enrollment->subject,
                ])
                ->all(),
            'forms' => EvaluationForm::query()
                ->with('teacher:id,name')
                ->withCount('responses')
                ->where('creator_id', $request->user()->id)
                ->latest()
                ->get()
                ->map(fn (EvaluationForm $form) => [
                    'id' => $form->id,
                    'category' => $form->category,
                    'title' => $form->title,
                    'teacherName' => $form->teacher?->name,
                    'subject' => $form->subject,
                    'teacherScope' => $form->teacher_scope,
                    'questionCount' => count($form->questions),
                    'responseCount' => $form->responses_count,
                    'isPublished' => $form->is_published,
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureGuidance($request);

        $validated = $request->validate([
            'category' => ['required', 'in:teacher,event,program,canteen,clinic,guidance'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'questions' => ['required', 'array', 'min:1', 'max:29'],
            'questions.*.prompt' => ['required', 'string', 'max:300'],
            'questions.*.type' => ['required', 'in:rating,multiple_choice,short_answer,paragraph'],
            'questions.*.options_text' => ['nullable', 'string', 'max:2000'],
        ]);

        $teacherId = null;
        $subject = null;
        $teacherScope = $validated['category'] === 'teacher'
            ? 'all_teachers'
            : 'specific_subject';

        $questions = [];

        foreach ($validated['questions'] as $index => $question) {
            $options = collect(preg_split('/\R/', $question['options_text'] ?? '') ?: [])
                ->map(fn (string $option) => trim($option))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($question['type'] === 'multiple_choice' && count($options) < 2) {
                throw ValidationException::withMessages([
                    "questions.$index.options_text" => 'Add at least two different answer choices.',
                ]);
            }

            $questions[] = [
                'id' => 'q'.$index,
                'prompt' => trim($question['prompt']),
                'type' => $question['type'],
                'options' => $question['type'] === 'multiple_choice' ? $options : [],
            ];
        }

        EvaluationForm::query()->create([
            'creator_id' => $request->user()->id,
            'teacher_id' => $teacherId,
            'category' => $validated['category'],
            'teacher_scope' => $teacherScope,
            'subject' => $subject,
            'title' => trim($validated['title']),
            'description' => $validated['description'] ?? null,
            'questions' => $questions,
            'is_published' => true,
        ]);

        return to_route('guidance.evaluations')->with('status', 'Evaluation form published.');
    }

    public function storeEnrollments(Request $request): RedirectResponse
    {
        $this->ensureGuidance($request);

        $validated = $request->validate([
            'teacher_id' => ['required', 'integer', 'exists:users,id'],
            'subject' => ['required', 'string', 'max:160'],
            'student_ids' => ['required', 'array', 'min:1', 'max:100'],
            'student_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);

        $teacher = User::query()
            ->whereKey($validated['teacher_id'])
            ->where('role', UserRole::Teacher->value)
            ->first();
        $students = User::query()
            ->whereKey($validated['student_ids'])
            ->where('role', UserRole::Student->value)
            ->get(['id']);

        if (! $teacher || $students->count() !== count($validated['student_ids'])) {
            throw ValidationException::withMessages([
                'student_ids' => 'Choose a valid teacher and student accounts.',
            ]);
        }

        DB::transaction(function () use ($students, $teacher, $validated) {
            foreach ($students as $student) {
                StudentSubjectEnrollment::query()->firstOrCreate([
                    'student_id' => $student->id,
                    'teacher_id' => $teacher->id,
                    'subject' => trim($validated['subject']),
                ]);
            }
        });

        return to_route('guidance.evaluations')->with('status', 'Students enrolled in subject.');
    }

    public function destroyEnrollment(Request $request, StudentSubjectEnrollment $enrollment): RedirectResponse
    {
        $this->ensureGuidance($request);
        $enrollment->delete();

        return to_route('guidance.evaluations')->with('status', 'Student removed from subject.');
    }

    public function show(Request $request, EvaluationForm $evaluationForm): Response
    {
        $this->ensureGuidance($request);
        abort_unless($evaluationForm->creator_id === $request->user()->id, 403);

        $evaluationForm->load(
            'teacher:id,name',
            'responses.student:id,name,student_number',
            'responses.evaluatedTeacher:id,name',
        );
        $responses = $evaluationForm->responses;
        $responseGroups = $responses
            ->groupBy(function (EvaluationResponse $response) use ($evaluationForm): string {
                $teacherId = $response->evaluated_teacher_id ?? $evaluationForm->teacher_id;
                $subject = $response->evaluated_subject ?? $evaluationForm->subject;

                return $teacherId === null
                    ? 'overall'
                    : $teacherId.'|'.mb_strtolower(trim((string) $subject));
            })
            ->map(function ($targetResponses) use ($evaluationForm): array {
                /** @var EvaluationResponse|null $firstResponse */
                $firstResponse = $targetResponses->first();

                return [
                    'teacherId' => $firstResponse?->evaluated_teacher_id
                        ?? $evaluationForm->teacher_id,
                    'teacherName' => $firstResponse?->evaluatedTeacher?->name
                        ?? $evaluationForm->teacher?->name,
                    'subject' => $firstResponse?->evaluated_subject ?? $evaluationForm->subject,
                    'responses' => $targetResponses
                        ->map(fn (EvaluationResponse $response) => [
                            'studentName' => $response->student->name,
                            'studentNumber' => $response->student->student_number,
                            'answers' => $response->answers,
                            'evaluatedTeacherName' => $response->evaluatedTeacher?->name,
                            'evaluatedSubject' => $response->evaluated_subject,
                            'submittedAt' => $response->submitted_at->toIso8601String(),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy(fn (array $group): string => mb_strtolower(
                (string) $group['teacherName'].'|'.(string) $group['subject'],
            ))
            ->values()
            ->all();
        $averageData = [];

        foreach ($evaluationForm->questions as $question) {
            if ($question['type'] !== 'rating') {
                continue;
            }

            $groups = $responses->groupBy(function (EvaluationResponse $response) use ($evaluationForm) {
                $teacherId = $response->evaluated_teacher_id ?? $evaluationForm->teacher_id;
                $subject = $response->evaluated_subject ?? $evaluationForm->subject;

                return $teacherId === null
                    ? 'overall'
                    : $teacherId.'|'.mb_strtolower((string) $subject);
            });

            foreach ($groups as $targetResponses) {
                $firstResponse = $targetResponses->first();

                if (! $firstResponse) {
                    continue;
                }

                $ratings = [];

                foreach ($targetResponses as $response) {
                    $value = $response->answers[$question['id']] ?? null;

                    if (is_string($value) && is_numeric($value)) {
                        $ratings[] = (float) $value;
                    }
                }

                if ($ratings === []) {
                    continue;
                }

                $averageData[] = [
                    'questionId' => $question['id'],
                    'prompt' => $question['prompt'],
                    'teacherId' => $firstResponse->evaluated_teacher_id
                        ?? $evaluationForm->teacher_id,
                    'teacherName' => $firstResponse->evaluated_teacher_id !== null
                        ? $firstResponse->evaluatedTeacher->name
                        : $evaluationForm->teacher?->name,
                    'subject' => $firstResponse->evaluated_subject ?? $evaluationForm->subject,
                    'average' => round(array_sum($ratings) / count($ratings), 2),
                    'responseCount' => count($ratings),
                ];
            }
        }

        return Inertia::render('guidance/evaluations/show', [
            'evaluation' => [
                'id' => $evaluationForm->id,
                'category' => $evaluationForm->category,
                'title' => $evaluationForm->title,
                'subject' => $evaluationForm->subject,
                'teacherName' => $evaluationForm->teacher?->name,
                'questions' => $evaluationForm->questions,
                'averageData' => $averageData,
                'responseGroups' => $responseGroups,
                'responses' => $responses
                    ->map(fn (EvaluationResponse $response) => [
                        'studentName' => $response->student->name,
                        'studentNumber' => $response->student->student_number,
                        'answers' => $response->answers,
                        'evaluatedTeacherName' => $response->evaluatedTeacher?->name,
                        'evaluatedSubject' => $response->evaluated_subject,
                        'submittedAt' => $response->submitted_at->toIso8601String(),
                    ])
                    ->all(),
            ],
        ]);
    }

    private function ensureGuidance(Request $request): void
    {
        abort_unless($request->user()->role === UserRole::Guidance, 403);
    }
}
