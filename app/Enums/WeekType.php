<?php

namespace App\Enums;

enum WeekType: string
{
    case Study = 'study';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Study => 'دراسة',
            self::Review => 'مراجعة',
        };
    }
}
