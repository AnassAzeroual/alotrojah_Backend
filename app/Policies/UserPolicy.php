<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class UserPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true);
    }

    public function view(User $user, User $model): bool
    {
        if ((int) $model->id === (int) $user->id) return true;

        return in_array($user->role, ['admin', 'supervisor'], true)
            && $this->sameCenter($user, $model->center_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true);
    }

    public function update(User $user, User $model): bool
    {
        if ((int) $model->id === (int) $user->id) return true; // own profile (role/center locked in controller)

        return in_array($user->role, ['admin', 'supervisor'], true)
            && $this->sameCenter($user, $model->center_id);
    }

    public function delete(User $user, User $model): bool
    {
        return $this->isAdmin($user) && (int) $model->id !== (int) $user->id;
    }
}
