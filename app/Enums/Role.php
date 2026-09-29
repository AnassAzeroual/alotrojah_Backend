<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Teacher = 'teacher';
    case Guardian = 'guardian';
    case Student = 'student';
    case Board = 'board';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'مدير',
            self::Supervisor => 'ناظر',
            self::Teacher => 'معلم',
            self::Guardian => 'ولي',
            self::Student => 'طالب',
            self::Board => 'مجلس الإدارة',
        };
    }
}
