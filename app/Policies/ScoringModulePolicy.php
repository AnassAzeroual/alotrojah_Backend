<?php

namespace App\Policies;

use App\Models\ScoringModule;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

/** Manager-only page (admin + supervisor). Teachers read via scoring-check. */
class ScoringModulePolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, ScoringModule $module): bool
    {
        return $this->isStaff($user);
    }

    public function manage(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true);
    }
}
