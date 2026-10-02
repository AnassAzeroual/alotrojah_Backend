<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WeeklyGoal;
use App\Policies\Concerns\CenterScoped;

class WeeklyGoalPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user) || $user->role === 'student';
    }

    public function view(User $user, WeeklyGoal $goal): bool
    {
        if ($this->isAdmin($user)) return true;

        return (int) $goal->student?->center_id === (int) $user->center_id;
    }

    public function manage(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor', 'teacher'], true);
    }
}
