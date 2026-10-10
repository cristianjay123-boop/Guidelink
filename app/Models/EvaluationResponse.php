<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $evaluation_form_id
 * @property int $student_id
 * @property int|null $evaluated_teacher_id
 * @property string|null $evaluated_subject
 * @property array<string, string> $answers
 * @property Carbon $submitted_at
 */
#[Fillable(['evaluation_form_id', 'student_id', 'evaluated_teacher_id', 'evaluated_subject', 'answers', 'submitted_at'])]
class EvaluationResponse extends Model
{
    /**
     * @return BelongsTo<EvaluationForm, $this>
     */
    public function evaluationForm(): BelongsTo
    {
        return $this->belongsTo(EvaluationForm::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function evaluatedTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_teacher_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'submitted_at' => 'datetime',
        ];
    }
}
