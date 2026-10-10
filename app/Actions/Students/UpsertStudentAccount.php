<?php

namespace App\Actions\Students;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertStudentAccount
{
    /**
     * Create or update a student without resetting an existing password by default.
     */
    public function handle(
        string $studentNumber,
        string $name,
        string $email,
        ?string $temporaryPassword = null,
    ): User {
        return DB::transaction(function () use ($studentNumber, $name, $email, $temporaryPassword) {
            $user = User::query()->firstOrNew(['login_id' => $studentNumber]);
            $isNew = ! $user->exists;

            if ($isNew && $temporaryPassword === null) {
                throw ValidationException::withMessages([
                    'temporary_password' => 'A temporary password is required for a new student account.',
                ]);
            }

            $user->name = $name;
            $user->email = $email;
            $user->student_number = $studentNumber;
            $user->login_id = $studentNumber;
            $user->role = UserRole::Student;

            if ($temporaryPassword !== null) {
                $user->password = $temporaryPassword;
                $user->must_change_password = true;
            }

            $user->save();

            return $user;
        });
    }
}
