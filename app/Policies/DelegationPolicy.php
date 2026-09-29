<?php

namespace App\Policies;

use App\Models\DelegationToken;
use App\Models\Group;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class DelegationPolicy
{
    use CenterScoped;

    /** Only the responsible teacher (or admin) opens access to their group. */
    public function generate(User $user, Group $group): bool
    {
        return $this->isAdmin($user) || (int) $group->teacher_id === (int) $user->id;
    }

    public function viewGroup(User $user, Group $group): bool
    {
        return $this->isAdmin($user) || (int) $group->teacher_id === (int) $user->id
            || ($user->role === 'supervisor' && $this->sameCenter($user, $group->center_id));
    }

    public function revoke(User $user, DelegationToken $token): bool
    {
        return $this->isAdmin($user) || (int) $token->granter_teacher_id === (int) $user->id;
    }
}
