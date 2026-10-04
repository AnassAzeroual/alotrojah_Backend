<?php

namespace App\Policies;

use App\Models\ScoringModule;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

/** Admin-only writes (Item 10). Teachers read via scoring-check. */
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
        return $this->isAdmin($user);
    }

    public function delete(User $user, ScoringModule $module): bool
    {
        return $this->isAdmin($user);
    }
}
