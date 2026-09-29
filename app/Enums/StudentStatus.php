<?php

namespace App\Enums;

enum StudentStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Graduated = 'graduated';
    case Left = 'left';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Paused => 'موقوف',
            self::Graduated => 'متخرج',
            self::Left => 'مغادر',
        };
    }
}
