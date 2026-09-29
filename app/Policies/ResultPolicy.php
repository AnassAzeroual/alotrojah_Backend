<?php

namespace App\Policies;

use App\Models\SeasonResult;
use App\Models\TermResult;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

/** Covers TermResult + SeasonResult. Honor flags: teacher AND manager (Q18). */
class ResultPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user) || in_array($user->role, ['guardian', 'student'], true);
    }

    public function view(User $user, TermResult|SeasonResult $result): bool
    {
        if ($this->isAdmin($user)) return true;

        return (int) $result->student?->center_id === (int) $user->center_id;
    }

    public function manage(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor', 'teacher'], true);
    }

    public function delete(User $user, TermResult|SeasonResult $result): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true);
    }
}
