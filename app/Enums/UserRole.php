<?php

namespace App\Enums;

enum UserRole: string
{
    case Student = 'student';
    case Teacher = 'teacher';
    case Guidance = 'guidance';
    case Dean = 'dean';
    case Superadmin = 'superadmin';

    /**
     * Get the display label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Superadmin => 'Superadmin',
            default => ucfirst($this->value),
        };
    }
}
