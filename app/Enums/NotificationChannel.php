<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Whatsapp = 'whatsapp';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Whatsapp => 'واتساب',
            self::Other => 'أخرى',
        };
    }
}
