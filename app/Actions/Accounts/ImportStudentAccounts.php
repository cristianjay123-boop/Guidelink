<?php

namespace App\Actions\Accounts;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Imports\SpreadsheetRows;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportStudentAccounts
{
    public const DEFAULT_TEMPORARY_PASSWORD = '1234567890-=';

    public function __construct(private SpreadsheetRows $spreadsheets) {}

    /**
     * @return array{created: int, updated: int}
     */
    public function import(UploadedFile $file): array
    {
        try {
            $rows = $this->spreadsheets->read($file, ['student_number']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'student_file' => implode(' ', collect($exception->errors())->flatten()->all()),
            ]);
        }
        $numbers = [];
        $errors = [];

        foreach ($rows as $row) {
            $studentNumber = $row['values']['student_number'];

            if ($studentNumber === '') {
                $errors[] = "Row {$row['row']}: student_number is required.";

                continue;
            }

            if (mb_strlen($studentNumber) > 64) {
                $errors[] = "Row {$row['row']}: student_number exceeds 64 characters.";

                continue;
            }

            $normalized = mb_strtolower($studentNumber);

            if (isset($numbers[$normalized])) {
                $errors[] = "Row {$row['row']}: duplicate student_number {$studentNumber}.";

                continue;
            }

            $numbers[$normalized] = $studentNumber;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'student_file' => implode(' ', $errors),
            ]);
        }

        if ($numbers === []) {
            throw ValidationException::withMessages([
                'student_file' => 'The spreadsheet contains no student numbers.',
            ]);
        }

        return DB::transaction(function () use ($numbers): array {
            $created = 0;
            $updated = 0;

            foreach ($numbers as $studentNumber) {
                $user = User::query()
                    ->where('student_number', $studentNumber)
                    ->orWhere('login_id', $studentNumber)
                    ->first();

                if ($user && $user->role !== UserRole::Student) {
                    throw ValidationException::withMessages([
                        'student_file' => "Student number {$studentNumber} belongs to a non-student account.",
                    ]);
                }

                $isNew = $user === null;
                $user ??= new User;
                $user->name = $user->name ?: 'Student '.$studentNumber;
                $user->email = $user->email ?: $this->placeholderEmail($studentNumber);
                $user->student_number = $studentNumber;
                $user->login_id = $studentNumber;
                $user->role = UserRole::Student;
                $user->password = self::DEFAULT_TEMPORARY_PASSWORD;
                $user->must_change_password = true;
                $user->save();

                $isNew ? $created++ : $updated++;
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }

    private function placeholderEmail(string $studentNumber): string
    {
        return 'student-'.substr(hash('sha256', $studentNumber), 0, 24).'@students.guidelink.local';
    }
}
