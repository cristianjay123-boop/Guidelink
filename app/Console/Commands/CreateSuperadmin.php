<?php

namespace App\Console\Commands;

use App\Actions\Accounts\UpsertManagedAccount;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CreateSuperadmin extends Command
{
    protected $signature = 'accounts:create-superadmin {name} {email} {login_id?}';

    protected $description = 'Create the initial Superadmin account';

    public function handle(UpsertManagedAccount $upsert): int
    {
        $name = (string) $this->argument('name');
        $email = (string) $this->argument('email');
        $loginIdOverride = $this->argument('login_id');
        $loginId = $upsert->loginIdFor(
            UserRole::Superadmin,
            $name,
            loginIdOverride: is_string($loginIdOverride) ? $loginIdOverride : null,
        );

        if (User::query()->where('role', UserRole::Superadmin->value)->exists()) {
            $this->error('A Superadmin account already exists.');

            return self::FAILURE;
        }

        if (User::query()->where('login_id', $loginId)->exists()) {
            $this->error("The login ID {$loginId} is already in use.");

            return self::FAILURE;
        }

        $password = $this->secret('Temporary password (at least 12 characters)');

        if (! is_string($password) || strlen($password) < 12 || $password !== $this->secret('Confirm temporary password')) {
            $this->error('The temporary passwords did not match or were too short.');

            return self::FAILURE;
        }

        try {
            $upsert->handle(
                UserRole::Superadmin,
                $name,
                $email,
                temporaryPassword: $password,
                loginIdOverride: is_string($loginIdOverride) ? $loginIdOverride : null,
            );
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Created Superadmin account: {$loginId}");

        return self::SUCCESS;
    }
}
