<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $teacher_id
 * @property string $semester
 * @property string $academic_year
 * @property string $course_code
 * @property string $descriptive_title
 * @property string $course_year
 * @property string|null $units
 */
#[Fillable([
    'teacher_id',
    'semester',
    'academic_year',
    'course_code',
    'descriptive_title',
    'course_year',
    'units',
])]
class TeacherSubjectLoad extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
