<?php

namespace App\Enums;

enum ModuleScope: string
{
    case Weekly = 'weekly';
    case Murajaa = 'murajaa';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'أسبوعي',
            self::Murajaa => 'مراجعة',
        };
    }
}
