<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Student;

/**
 * Own-group narrowing for student users. Staff get null (no narrowing —
 * their center scope comes from the calling query). One implementation shared
 * by every controller that serves student-visible feeds.
 */
trait StudentGroupScope
{
    /** @return int[]|null */
    private function studentGroupIds($user): ?array
    {
        if ($user->role !== 'student') return null;

        return Student::where('user_id', $user->id)->whereNotNull('group_id')
            ->pluck('group_id')->map(fn ($v) => (int) $v)->all();
    }
}
