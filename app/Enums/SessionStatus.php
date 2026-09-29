<?php

namespace App\Enums;

enum SessionStatus: string
{
    case Planned = 'planned';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'مبرمجة',
            self::Done => 'منجزة',
            self::Cancelled => 'ملغاة',
        };
    }
}
