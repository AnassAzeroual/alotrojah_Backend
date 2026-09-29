<?php

namespace App\Enums;

enum Audience: string
{
    case All = 'all';
    case Teachers = 'teachers';
    case Manager = 'manager';
    case My_students = 'my_students';

    public function label(): string
    {
        return match ($this) {
            self::All => 'عام',
            self::Teachers => 'المعلمون',
            self::Manager => 'المدير',
            self::My_students => 'طلابي',
        };
    }
}
