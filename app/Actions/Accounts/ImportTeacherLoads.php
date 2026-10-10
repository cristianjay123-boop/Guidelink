<?php

namespace App\Actions\Accounts;

use App\Support\Imports\SpreadsheetRows;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ImportTeacherLoads
{
    public function __construct(
        private SpreadsheetRows $spreadsheets,
        private UpsertTeacherLoads $upsert,
    ) {}

    /**
     * @return array{created: int, updated: int, loads: int}
     */
    public function import(UploadedFile $file): array
    {
        try {
            $rows = $this->spreadsheets->read($file, [
                'teacher_name',
                'email',
                'semester',
                'academic_year',
                'course_code',
                'descriptive_title',
                'course_year',
                'units',
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'teacher_file' => implode(' ', collect($exception->errors())->flatten()->all()),
            ]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'teacher_file' => 'The spreadsheet contains no teacher-load rows.',
            ]);
        }

        $records = [];
        $errors = [];

        foreach ($rows as $row) {
            $values = $row['values'];
            $rowErrors = [];

            foreach (['teacher_name', 'semester', 'academic_year', 'descriptive_title'] as $field) {
                if ($values[$field] === '') {
                    $rowErrors[] = "{$field} is required";
                }
            }

            if ($values['email'] !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
                $rowErrors[] = 'email must be a valid email address';
            }

            if ($values['units'] !== '' && (! is_numeric($values['units']) || (float) $values['units'] < 0 || (float) $values['units'] > 999.99)) {
                $rowErrors[] = 'units must be a number between 0 and 999.99';
            }

            if ($rowErrors !== []) {
                $errors[] = 'Row '.$row['row'].': '.implode('; ', $rowErrors).'.';

                continue;
            }

            $records[] = $values;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'teacher_file' => implode(' ', $errors),
            ]);
        }

        return $this->upsert->handle($records);
    }
}
