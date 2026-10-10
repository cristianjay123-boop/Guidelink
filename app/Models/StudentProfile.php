<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'photo_path',
    'last_name',
    'first_name',
    'middle_name',
    'personal',
    'emergency_contact',
    'education',
    'health',
    'interests',
    'family',
    'socioeconomic',
    'psychological_tests',
])]
class StudentProfile extends Model
{
    protected function casts(): array
    {
        return [
            'last_name' => 'encrypted',
            'first_name' => 'encrypted',
            'middle_name' => 'encrypted',
            'personal' => 'encrypted:array',
            'emergency_contact' => 'encrypted:array',
            'education' => 'encrypted:array',
            'health' => 'encrypted:array',
            'interests' => 'encrypted:array',
            'family' => 'encrypted:array',
            'socioeconomic' => 'encrypted:array',
            'psychological_tests' => 'encrypted:array',
        ];
    }

    /**
     * @return array{course: string|null, yearLevel: string|null}
     */
    public function academicDetails(): array
    {
        $personal = $this->personal ?? [];
        $course = trim((string) ($personal['course'] ?? ''));
        $yearLevel = trim((string) ($personal['year_level'] ?? ''));
        $legacyCourseYear = trim((string) ($personal['course_year'] ?? ''));

        if ($legacyCourseYear !== '' && ($course === '' || $yearLevel === '')) {
            if (preg_match('/^(.+?)[\s,|\/-]+((?:first|second|third|fourth|fifth|[1-5](?:st|nd|rd|th)?)(?:\s+year)?)$/i', $legacyCourseYear, $matches)) {
                $course = $course !== '' ? $course : trim($matches[1]);
                $yearLevel = $yearLevel !== '' ? $yearLevel : trim($matches[2]);
            } elseif ($course === '') {
                $course = $legacyCourseYear;
            }
        }

        return [
            'course' => $course !== '' ? $course : null,
            'yearLevel' => $yearLevel !== '' ? $yearLevel : null,
        ];
    }

    /**
     * Get the student who owns this private inventory.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
