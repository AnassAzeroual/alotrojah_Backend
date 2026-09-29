<?php

namespace App\Policies;

use App\Models\Center;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class CenterPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, Center $center): bool
    {
        return $this->sameCenter($user, $center->id);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Center $center): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Center $center): bool
    {
        return false; // centers are never deleted (isolation anchor)
    }
}
