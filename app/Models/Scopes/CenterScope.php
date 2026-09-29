<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Defense-in-depth center isolation for staff roles.
 * Skipped for: console, unauthenticated, admin/board.
 * NOTE: cross-center delegation bypasses via withoutGlobalScope() in ScoreEntryService.
 */
class CenterScope implements Scope
{
    protected static bool $resolvingUser = false;

    public function apply(Builder $builder, Model $model): void
    {
        if (app()->runningInConsole()) return;
        // auth()->user() queries the users table, which carries this same
        // scope -> without this guard every authenticated query recurses forever.
        if (static::$resolvingUser) return;

        static::$resolvingUser = true;
        try {
            $user = auth('api')->user();
        } catch (\Throwable) {
            $user = null;
        } finally {
            static::$resolvingUser = false;
        }
        if (! $user) return;
        if (in_array($user->role, ['admin', 'board'], true)) return;
        if (! in_array($user->role, ['supervisor', 'teacher'], true)) return;
        if ($user->center_id === null) return;

        $table = $model->getTable();
        if ($table === 'centers') {
            $builder->where($table.'.id', (int) $user->center_id);
        } elseif ($table === 'guardians') {
            $builder->whereHas('students', fn ($s) => $s->where('students.center_id', (int) $user->center_id));
        } else {
            $builder->where($table.'.center_id', (int) $user->center_id);
        }
    }
}
