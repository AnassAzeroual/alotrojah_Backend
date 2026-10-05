<?php

namespace App\Policies;

use App\Models\Level;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class LevelPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user) || $user->role === 'student';
    }

    /** Level writes are admin-only (Item: levels CRUD). */
    public function manage(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Level $level): bool
    {
        return $this->isAdmin($user);
    }
}
