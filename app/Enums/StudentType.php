<?php

namespace App\Enums;

enum StudentType: string
{
    case Child = 'child';
    case Adult = 'adult';

    public function label(): string
    {
        return match ($this) {
            self::Child => 'طفل +4',
            self::Adult => 'كبير +18',
        };
    }
}
