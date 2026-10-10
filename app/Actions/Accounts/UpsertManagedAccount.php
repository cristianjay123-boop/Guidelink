<?php

namespace App\Actions\Accounts;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpsertManagedAccount
{
    public function loginIdFor(
        UserRole $role,
        string $name,
        ?string $studentNumber = null,
        ?string $loginIdOverride = null,
    ): string {
        if ($loginIdOverride !== null) {
            $loginIdOverride = trim($loginIdOverride);

            if ($role !== UserRole::Superadmin || ! preg_match('/^[A-Za-z0-9._-]{3,64}$/', $loginIdOverride)) {
                throw ValidationException::withMessages([
                    'login_id' => 'A custom login ID is allowed only for Superadmin accounts and must be 3 to 64 letters, numbers, dots, underscores, or hyphens.',
                ]);
            }

            return $loginIdOverride;
        }

        if ($role === UserRole::Student) {
            $studentNumber = trim((string) $studentNumber);

            if ($studentNumber === '') {
                throw ValidationException::withMessages([
                    'student_number' => 'A student number is required for student accounts.',
                ]);
            }

            return $studentNumber;
        }

        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $titles = ['dr', 'dr.', 'mr', 'mr.', 'mrs', 'mrs.', 'ms', 'ms.', 'miss', 'prof', 'prof.', 'professor', 'atty', 'atty.', 'rev', 'rev.'];
        $suffixes = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'phd', 'ph.d.'];
        $parts = array_values(array_filter(
            $parts,
            fn (string $part, int $index) => ! ($index === 0 && in_array(mb_strtolower($part), $titles, true))
                && ! in_array(mb_strtolower($part), $suffixes, true),
            ARRAY_FILTER_USE_BOTH,
        ));

        if (count($parts) < 2) {
            throw ValidationException::withMessages([
                'name' => 'Enter a first and last name so the login ID can be generated.',
            ]);
        }

        $firstName = Str::slug($parts[0], '');
        $lastName = Str::slug($parts[array_key_last($parts)], '');

        return $lastName.'.'.$firstName;
    }

    public function handle(
        UserRole $role,
        string $name,
        string $email,
        ?string $studentNumber = null,
        ?string $temporaryPassword = null,
        ?string $loginIdOverride = null,
    ): User {
        $loginId = $this->loginIdFor($role, $name, $studentNumber, $loginIdOverride);

        return DB::transaction(function () use ($role, $name, $email, $studentNumber, $temporaryPassword, $loginId) {
            $user = User::query()->where('login_id', $loginId)->first() ?? new User;
            $isNew = ! $user->exists;

            if (! $isNew && $user->role !== $role) {
                throw ValidationException::withMessages([
                    'login_id' => 'That login ID belongs to an account with a different role.',
                ]);
            }

            if ($isNew && $temporaryPassword === null) {
                throw ValidationException::withMessages([
                    'temporary_password' => 'A temporary password is required for new accounts.',
                ]);
            }

            $user->name = $name;
            $user->email = $email;
            $user->login_id = $loginId;
            $user->student_number = $role === UserRole::Student ? $studentNumber : null;
            $user->role = $role;

            if ($temporaryPassword !== null) {
                $user->password = $temporaryPassword;
                $user->must_change_password = true;
            }

            $user->save();

            return $user;
        });
    }
}
