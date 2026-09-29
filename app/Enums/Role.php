<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Teacher = 'teacher';
    case Examiner = 'examiner';
    case Guardian = 'guardian';
    case Board = 'board';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'مدير',
            self::Supervisor => 'ناظر',
            self::Teacher => 'معلم',
            self::Examiner => 'مختبر',
            self::Guardian => 'ولي',
            self::Board => 'مجلس الإدارة',
        };
    }
}
