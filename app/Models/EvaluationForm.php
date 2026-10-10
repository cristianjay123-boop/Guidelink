<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $creator_id
 * @property int|null $teacher_id
 * @property string $category
 * @property string $teacher_scope
 * @property string|null $subject
 * @property string $title
 * @property string|null $description
 * @property list<array{id: string, prompt: string, type: string, options: list<string>}> $questions
 * @property bool $is_published
 * @property int $responses_count
 */
#[Fillable([
    'creator_id',
    'teacher_id',
    'category',
    'teacher_scope',
    'subject',
    'title',
    'description',
    'questions',
    'is_published',
])]
class EvaluationForm extends Model
{
    /**
     * @param  Builder<EvaluationForm>  $query
     * @return Builder<EvaluationForm>
     */
    public function scopeAvailableToStudent(Builder $query, int $studentId): Builder
    {
        return $query->where(function (Builder $query) use ($studentId) {
            $query->where('category', '!=', 'teacher')
                ->orWhereExists(function ($enrollments) use ($studentId) {
                    $enrollments->selectRaw('1')
                        ->from('student_subject_enrollments')
                            ->where('student_subject_enrollments.student_id', $studentId)
                        ->where(function ($target) {
                            $target->where('evaluation_forms.teacher_scope', 'all_teachers')
                                ->orWhere(function ($specificTarget) {
                                    $specificTarget
                                        ->whereColumn('student_subject_enrollments.teacher_id', 'evaluation_forms.teacher_id')
                                        ->whereRaw('LOWER(student_subject_enrollments.subject) = LOWER(evaluation_forms.subject)');
                                });
                        });
                });
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * @return HasMany<EvaluationResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(EvaluationResponse::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'is_published' => 'boolean',
        ];
    }
}
