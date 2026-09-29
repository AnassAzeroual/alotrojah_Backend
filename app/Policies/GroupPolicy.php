<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class GroupPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, Group $group): bool
    {
        return $this->sameCenter($user, $group->center_id);
    }

    public function update(User $user, Group $group): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true)
            || ($user->role === 'teacher' && (int) $group->teacher_id === (int) $user->id);
    }

    public function delegate(User $user, Group $group): bool
    {
        return $this->isAdmin($user) || (int) $group->teacher_id === (int) $user->id;
    }
}
