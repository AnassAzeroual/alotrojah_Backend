<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'في الانتظار',
            self::Sent => 'أرسلت',
            self::Failed => 'فشلت',
        };
    }
}
