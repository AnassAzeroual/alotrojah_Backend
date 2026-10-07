<?php

namespace App\Enums;

enum Weekday: string
{
    case Mon = 'Mon';
    case Tue = 'Tue';
    case Wed = 'Wed';
    case Thu = 'Thu';
    case Fri = 'Fri';
    case Sat = 'Sat';
    case Sun = 'Sun';

    /** Canonical week order (generation + display), independent of locale. */
    public static function ordered(): array
    {
        return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    }
}
