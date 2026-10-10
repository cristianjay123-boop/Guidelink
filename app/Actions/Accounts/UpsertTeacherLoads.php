<?php

namespace App\Actions\Accounts;

use App\Enums\UserRole;
use App\Models\TeacherSubjectLoad;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpsertTeacherLoads
{
    /**
     * @param  list<array{teacher_name: string, email?: string|null, semester: string, academic_year: string, course_code?: string|null, descriptive_title: string, course_year?: string|null, units?: string|int|float|null}>  $rows
     * @return array{created: int, updated: int, loads: int}
     */
    public function handle(array $rows): array
    {
        $teachers = [];

        foreach ($rows as $index => $row) {
            $name = trim($row['teacher_name']);
            $key = mb_strtolower((string) preg_replace('/\s+/', ' ', $name));
            $email = trim((string) ($row['email'] ?? ''));

            if (isset($teachers[$key]) && $teachers[$key]['email'] !== '' && $email !== ''
                && mb_strtolower($teachers[$key]['email']) !== mb_strtolower($email)) {
                throw ValidationException::withMessages([
                    'teacher_file' => "Teacher email differs between repeated rows for {$name}.",
                ]);
            }

            $teachers[$key] ??= [
                'name' => $name,
                'email' => $email,
                'login_id' => app(UpsertManagedAccount::class)->loginIdFor(UserRole::Teacher, $name),
            ];
            $teachers[$key]['email'] = $teachers[$key]['email'] ?: $email;

            $row['course_code'] = trim((string) ($row['course_code'] ?? ''));
            $row['course_year'] = trim((string) ($row['course_year'] ?? ''));
            $row['descriptive_title'] = trim($row['descriptive_title']);
            $rows[$index] = $row;
        }

        $loginIds = [];

        foreach ($teachers as $teacher) {
            if (isset($loginIds[$teacher['login_id']]) && $loginIds[$teacher['login_id']] !== $teacher['name']) {
                throw ValidationException::withMessages([
                    'teacher_file' => "More than one teacher generates the login ID {$teacher['login_id']}. Review the names before importing.",
                ]);
            }

            $loginIds[$teacher['login_id']] = $teacher['name'];
        }

        return DB::transaction(function () use ($rows, $teachers): array {
            $teacherModels = [];
            $created = 0;
            $updated = 0;

            foreach ($teachers as $key => $teacherData) {
                $teacher = User::query()->where('login_id', $teacherData['login_id'])->first();

                if ($teacher && $teacher->role !== UserRole::Teacher) {
                    throw ValidationException::withMessages([
                        'teacher_file' => "The generated login ID {$teacherData['login_id']} belongs to a non-teacher account.",
                    ]);
                }

                $isNew = $teacher === null;
                $teacher ??= new User;
                $email = $teacherData['email'] !== ''
                    ? $teacherData['email']
                    : ($teacher->email ?: $this->placeholderEmail($teacherData['login_id']));

                $emailQuery = User::query()->where('email', $email);

                if ($teacher->exists) {
                    $emailQuery->whereKeyNot($teacher->id);
                }

                if ($emailQuery->exists()) {
                    throw ValidationException::withMessages([
                        'teacher_file' => "The email {$email} is already used by another account.",
                    ]);
                }

                $teacher->name = $teacherData['name'];
                $teacher->email = $email;
                $teacher->login_id = $teacherData['login_id'];
                $teacher->role = UserRole::Teacher;

                if ($isNew) {
                    $teacher->password = ImportStudentAccounts::DEFAULT_TEMPORARY_PASSWORD;
                    $teacher->must_change_password = true;
                }

                $teacher->save();
                $teacherModels[$key] = $teacher;
                $isNew ? $created++ : $updated++;
            }

            $loads = 0;
            $seenLoads = [];

            foreach ($rows as $row) {
                $key = mb_strtolower((string) preg_replace('/\s+/', ' ', trim($row['teacher_name'])));
                $teacher = $teacherModels[$key];
                $loadKey = implode('|', [
                    $teacher->id,
                    mb_strtolower(trim($row['semester'])),
                    mb_strtolower(trim($row['academic_year'])),
                    mb_strtolower(trim((string) ($row['course_code'] ?? ''))),
                    mb_strtolower(trim($row['descriptive_title'])),
                ]);

                if (isset($seenLoads[$loadKey])) {
                    throw ValidationException::withMessages([
                        'teacher_file' => "The spreadsheet contains a duplicate subject-load row for {$row['teacher_name']}: {$row['descriptive_title']}.",
                    ]);
                }

                $seenLoads[$loadKey] = true;

                TeacherSubjectLoad::query()->updateOrCreate(
                    [
                        'teacher_id' => $teacher->id,
                        'semester' => trim($row['semester']),
                        'academic_year' => trim($row['academic_year']),
                        'course_code' => trim((string) ($row['course_code'] ?? '')),
                        'descriptive_title' => trim($row['descriptive_title']),
                    ],
                    [
                        'course_year' => trim((string) ($row['course_year'] ?? '')),
                        'units' => isset($row['units']) && $row['units'] !== ''
                            ? (float) $row['units']
                            : null,
                    ],
                );
                $loads++;
            }

            return ['created' => $created, 'updated' => $updated, 'loads' => $loads];
        });
    }

    private function placeholderEmail(string $loginId): string
    {
        return 'teacher-'.substr(hash('sha256', Str::lower($loginId)), 0, 24).'@teachers.guidelink.local';
    }
}
