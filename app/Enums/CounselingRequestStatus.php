<?php

namespace App\Enums;

enum CounselingRequestStatus: string
{
    case Pending = 'pending';
    case Closed = 'closed';
    case Accepted = 'accepted';
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Unsuccessful = 'unsuccessful';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting response',
            self::Closed => 'Declined / closed',
            self::Accepted => 'Awaiting slot selection',
            self::Scheduled => 'Scheduled',
            self::Completed => 'Completed',
            self::Unsuccessful => 'Not successful (student absent)',
        };
    }
}
