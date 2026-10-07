<?php

namespace App\Policies;

use App\Models\TermPlan;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class TermPlanPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user) || $user->role === 'student';
    }

    public function view(User $user, TermPlan $plan): bool
    {
        if ($this->isAdmin($user)) return true;

        return (int) $plan->student?->center_id === (int) $user->center_id;
    }

    public function manage(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor', 'teacher'], true);
    }

    public function delete(User $user, TermPlan $plan): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true)
            && $this->sameCenter($user, $plan->student?->center_id);
    }
}
