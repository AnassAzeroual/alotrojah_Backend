<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * READ-ONLY MODEL — QURAN IMMUTABILITY LAW.
 *
 * `quran_verses` holds the holy Quran text (Tanzil, machine-imported by
 * migration `000013` — the ONLY writer, via the query builder).
 * No API endpoint, seeder, tinker session, or future code may EVER
 * create, update, or delete a verse. Any write attempt throws.
 * If the text source ever changes upstream, the ONLY legal path is a
 * NEW migration that re-imports machine-side (never hand-typed).
 */
class QuranVerse extends Model
{
    protected $table = 'quran_verses';

    /** Mass assignment is fully closed — verses are never built from input. */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $refuse = function () {
            throw new LogicException(
                'quran_verses is read-only: the Quran text can never be created, updated, or deleted by application code.'
            );
        };
        static::creating($refuse);
        static::updating($refuse);
        static::saving($refuse);
        static::deleting($refuse);
    }
}
