<?php

namespace App\Http\Controllers\Students;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\EvaluationForm;
use App\Models\EvaluationResponse;
use App\Models\StudentSubjectEnrollment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StudentEvaluationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensureStudent($request);

        $enrollments = StudentSubjectEnrollment::query()
            ->where('student_id', $request->user()->id)
            ->get(['teacher_id', 'subject']);
        $studentTargetKeys = $enrollments
            ->map(fn (StudentSubjectEnrollment $enrollment) => $this->targetKey($enrollment->teacher_id, $enrollment->subject))
            ->unique()
            ->values()
            ->all();
        $submittedResponses = EvaluationResponse::query()
            ->where('student_id', $request->user()->id)
            ->get(['evaluation_form_id', 'evaluated_teacher_id', 'evaluated_subject']);
        $submittedFormIds = $submittedResponses
            ->pluck('evaluation_form_id')
            ->map(fn (int $id) => $id)
            ->all();
        $submittedTargetKeysByForm = $submittedResponses
            ->whereNotNull('evaluated_teacher_id')
            ->groupBy('evaluation_form_id')
            ->map(fn ($responses) => $responses
                ->map(fn (EvaluationResponse $response) => $this->targetKey($response->evaluated_teacher_id, $response->evaluated_subject ?? ''))
                ->unique()
                ->values()
                ->all())
            ->all();

        return Inertia::render('student/evaluations/index', [
            'forms' => EvaluationForm::query()
                ->availableToStudent($request->user()->id)
                ->with('teacher:id,name')
                ->where('is_published', true)
                ->latest()
                ->get()
                ->map(function (EvaluationForm $form) use ($submittedFormIds, $submittedTargetKeysByForm, $studentTargetKeys) {
                    $completedTargetCount = count(array_intersect(
                        $studentTargetKeys,
                        $submittedTargetKeysByForm[$form->id] ?? [],
                    ));

                    return [
                        'id' => $form->id,
                        'category' => $form->category,
                        'title' => $form->title,
                        'description' => $form->description,
                        'teacherName' => $form->teacher?->name,
                        'subject' => $form->subject,
                        'teacherScope' => $form->teacher_scope,
                        'questionCount' => count($form->questions),
                        'completedTeacherCount' => $completedTargetCount,
                        'totalTeacherCount' => count($studentTargetKeys),
                        'isSubmitted' => $form->category === 'teacher' && $form->teacher_scope === 'all_teachers'
                            ? $studentTargetKeys !== [] && $completedTargetCount === count($studentTargetKeys)
                            : in_array($form->id, $submittedFormIds, true),
                    ];
                })
                ->all(),
        ]);
    }

    public function show(Request $request, EvaluationForm $evaluationForm): Response|RedirectResponse
    {
        $this->ensureStudent($request);
        abort_unless($evaluationForm->is_published, 404);
        abort_unless(
            EvaluationForm::query()
                ->availableToStudent($request->user()->id)
                ->whereKey($evaluationForm->id)
                ->exists(),
            404,
        );

        $evaluationForm->load('teacher:id,name');
        $teachers = [];

        if ($evaluationForm->category === 'teacher' && $evaluationForm->teacher_scope === 'all_teachers') {
            $completedTargets = $evaluationForm->responses()
                ->where('student_id', $request->user()->id)
                ->get(['evaluated_teacher_id', 'evaluated_subject'])
                ->map(fn (EvaluationResponse $response) => $this->targetKey($response->evaluated_teacher_id, $response->evaluated_subject ?? ''))
                ->all();
            $teachers = StudentSubjectEnrollment::query()
                ->with('teacher:id,name')
                ->where('student_id', $request->user()->id)
                ->orderBy('subject')
                ->get(['id', 'teacher_id', 'subject'])
                ->filter(fn (StudentSubjectEnrollment $enrollment) => ! in_array(
                    $this->targetKey($enrollment->teacher_id, $enrollment->subject),
                    $completedTargets,
                    true,
                ))
                ->values()
                ->map(fn (StudentSubjectEnrollment $enrollment) => [
                    'enrollmentId' => $enrollment->id,
                    'teacherName' => $enrollment->teacher->name,
                    'subject' => $enrollment->subject,
                ])
                ->all();

            if ($teachers === []) {
                return to_route('student.evaluations')->with('status', 'You have evaluated every current teacher.');
            }
        } elseif ($evaluationForm->responses()->where('student_id', $request->user()->id)->exists()) {
            return to_route('student.evaluations')->with('status', 'You already submitted this evaluation.');
        }

        return Inertia::render('student/evaluations/show', [
            'evaluation' => [
                'id' => $evaluationForm->id,
                'category' => $evaluationForm->category,
                'title' => $evaluationForm->title,
                'description' => $evaluationForm->description,
                'teacherName' => $evaluationForm->teacher?->name,
                'subject' => $evaluationForm->subject,
                'teacherScope' => $evaluationForm->teacher_scope,
                'teachers' => $teachers,
                'questions' => $evaluationForm->questions,
            ],
        ]);
    }

    public function submit(Request $request, EvaluationForm $evaluationForm): RedirectResponse
    {
        $this->ensureStudent($request);
        abort_unless($evaluationForm->is_published, 404);
        abort_unless(
            EvaluationForm::query()
                ->availableToStudent($request->user()->id)
                ->whereKey($evaluationForm->id)
                ->exists(),
            404,
        );
        $rules = ['answers' => ['required', 'array']];

        if ($evaluationForm->category === 'teacher' && $evaluationForm->teacher_scope === 'all_teachers') {
            $rules['enrollment_id'] = [
                'required',
                'integer',
                Rule::exists('student_subject_enrollments', 'id')
                    ->where('student_id', $request->user()->id),
            ];
        } else {
            $rules['enrollment_id'] = ['prohibited'];
        }

        foreach ($evaluationForm->questions as $question) {
            $answerPath = 'answers.'.$question['id'];
            $rules[$answerPath] = match ($question['type']) {
                'rating' => ['required', 'integer', 'between:1,5'],
                'multiple_choice' => ['required', 'string', Rule::in($question['options'])],
                'short_answer' => ['required', 'string', 'max:255'],
                'paragraph' => ['required', 'string', 'max:5000'],
                default => ['prohibited'],
            };
        }

        $validated = $request->validate($rules);
        $evaluatedTeacherId = $evaluationForm->teacher_id;
        $evaluatedSubject = $evaluationForm->subject;

        if ($evaluationForm->category === 'teacher' && $evaluationForm->teacher_scope === 'all_teachers') {
            $enrollment = StudentSubjectEnrollment::query()
                ->whereKey($validated['enrollment_id'])
                ->where('student_id', $request->user()->id)
                ->firstOrFail();
            $evaluatedTeacherId = $enrollment->teacher_id;
            $evaluatedSubject = $enrollment->subject;

            $existingResponse = $evaluationForm->responses()
                ->where('student_id', $request->user()->id)
                ->where('evaluated_teacher_id', $evaluatedTeacherId)
                ->whereRaw('LOWER(evaluated_subject) = LOWER(?)', [$evaluatedSubject])
                ->exists();
        } else {
            $existingResponse = $evaluationForm->responses()
                ->where('student_id', $request->user()->id)
                ->exists();
        }

        abort_unless(! $existingResponse, 409);
        $answers = [];

        foreach ($evaluationForm->questions as $question) {
            $answers[$question['id']] = (string) $validated['answers'][$question['id']];
        }

        EvaluationResponse::query()->create([
            'evaluation_form_id' => $evaluationForm->id,
            'student_id' => $request->user()->id,
            'evaluated_teacher_id' => $evaluatedTeacherId,
            'evaluated_subject' => $evaluatedSubject,
            'answers' => $answers,
            'submitted_at' => now(),
        ]);

        return to_route('student.evaluations')->with('status', 'Evaluation submitted. Thank you for your feedback.');
    }

    private function ensureStudent(Request $request): void
    {
        abort_unless($request->user()->role === UserRole::Student, 403);
    }

    private function targetKey(int $teacherId, string $subject): string
    {
        return $teacherId.'|'.mb_strtolower(trim($subject));
    }
}
