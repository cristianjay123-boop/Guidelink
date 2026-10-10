<?php

namespace App\Actions\Accounts;

use App\Enums\UserRole;
use App\Models\StudentSubjectEnrollment;
use App\Models\TeacherSubjectLoad;
use App\Models\User;
use App\Support\Imports\SpreadsheetRows;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportSubjectEnrollments
{
    public function __construct(private SpreadsheetRows $spreadsheets) {}

    /**
     * @return array{created: int, existing: int}
     */
    public function import(UploadedFile $file): array
    {
        try {
            $rows = $this->spreadsheets->read($file, [
                'course_code',
                'student_number',
                'semester',
                'academic_year',
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'enrollment_file' => implode(' ', collect($exception->errors())->flatten()->all()),
            ]);
        }

        $loads = TeacherSubjectLoad::query()
            ->with('teacher:id,name,role')
            ->where('course_code', '!=', '')
            ->get();
        $studentsByNumber = [];

        foreach (User::query()->where('role', UserRole::Student->value)->get(['id', 'student_number', 'login_id']) as $student) {
            foreach (array_unique(array_filter([$student->student_number, $student->login_id])) as $studentNumber) {
                $studentsByNumber[mb_strtolower(trim($studentNumber))][] = $student;
            }
        }

        $enrollments = [];
        $seen = [];
        $errors = [];

        foreach ($rows as $row) {
            $courseCode = trim($row['values']['course_code']);
            $studentNumber = $row['values']['student_number'];
            $semester = trim($row['values']['semester']);
            $academicYear = trim($row['values']['academic_year']);

            if ($this->normalizeHeader($studentNumber) === 'student_number') {
                continue;
            }

            $rowErrors = [];

            if ($courseCode === '' || mb_strlen($courseCode) > 80) {
                $rowErrors[] = 'course_code is required and must be at most 80 characters';
            }

            if ($studentNumber === '') {
                $rowErrors[] = 'student_number is required';
            }

            if (($semester === '') !== ($academicYear === '')) {
                $rowErrors[] = 'semester and academic_year must either both be provided or both be empty';
            }

            $courseLoads = $loads
                ->filter(fn (TeacherSubjectLoad $load) => $this->normalizeCourseCode($load->course_code) === $this->normalizeCourseCode($courseCode));
            $matchingLoads = $courseLoads;

            if ($semester !== '' && $academicYear !== '') {
                $termLoads = $courseLoads
                    ->filter(fn (TeacherSubjectLoad $load) => $this->normalizeTerm($load->semester) === $this->normalizeTerm($semester)
                        && $this->normalizeTerm($load->academic_year) === $this->normalizeTerm($academicYear));

                if ($termLoads->isNotEmpty() || $courseLoads->unique('teacher_id')->count() > 1) {
                    $matchingLoads = $termLoads;
                }
            }

            $matchingLoads = $matchingLoads->unique('teacher_id')->values();
            $matchingStudents = $studentsByNumber[mb_strtolower(trim($studentNumber))] ?? [];

            if ($courseCode !== '' && $courseLoads->isEmpty()) {
                $rowErrors[] = "course_code '{$courseCode}' was not found in teacher loads";
            } elseif ($matchingLoads->isEmpty()) {
                $rowErrors[] = "course_code '{$courseCode}' exists in teacher loads, but no teacher load matches semester '{$semester}' and academic_year '{$academicYear}'";
            } elseif ($matchingLoads->count() > 1) {
                $rowErrors[] = "course_code '{$courseCode}' matches multiple teachers; provide the correct semester and academic_year";
            }

            if ($studentNumber !== '' && count($matchingStudents) !== 1) {
                $rowErrors[] = count($matchingStudents) === 0
                    ? "student_number '{$studentNumber}' was not found"
                    : "student_number '{$studentNumber}' is ambiguous";
            }

            if ($rowErrors !== []) {
                $errors[] = 'Row '.$row['row'].': '.implode('; ', $rowErrors).'.';

                continue;
            }

            $load = $matchingLoads->first();
            $student = $matchingStudents[0];
            $key = $student->id.'|'.$load->teacher_id.'|'.mb_strtolower(trim($load->course_code));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $enrollments[] = [
                'student_id' => $student->id,
                'teacher_id' => $load->teacher_id,
                'subject' => trim($load->course_code),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'enrollment_file' => implode(' ', $errors),
            ]);
        }

        if ($enrollments === []) {
            throw ValidationException::withMessages([
                'enrollment_file' => 'The spreadsheet contains no enrollment rows.',
            ]);
        }

        return DB::transaction(function () use ($enrollments): array {
            $created = 0;
            $existing = 0;

            foreach ($enrollments as $enrollment) {
                $model = StudentSubjectEnrollment::query()->firstOrCreate($enrollment);
                $model->wasRecentlyCreated ? $created++ : $existing++;
            }

            return ['created' => $created, 'existing' => $existing];
        });
    }

    private function normalizeCourseCode(string $courseCode): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($courseCode)));
    }

    private function normalizeTerm(string $term): string
    {
        $term = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($term)));

        return match ($term) {
            'firstsemester', 'firstsem', '1stsemester', '1stsem', '1semester', '1sem' => '1',
            'secondsemester', 'secondsem', '2ndsemester', '2ndsem', '2semester', '2sem' => '2',
            'thirdsemester', 'thirdsem', '3rdsemester', '3rdsem', '3semester', '3sem' => '3',
            default => $term,
        };
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $header), '_');
    }
}
