<?php

namespace App\Policies;

use App\Models\Guardian;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class GuardianPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        // guardians list only their own record (query narrows by user_id)
        return $this->isStaff($user) || $user->role === 'guardian';
    }

    public function view(User $user, Guardian $guardian): bool
    {
        if ($this->isAdmin($user)) return true;
        if ($user->role === 'guardian' && (int) $guardian->user_id === (int) $user->id) return true;

        // staff: guardian must serve a student of their center
        return $this->isStaff($user) && $guardian->students()
            ->where('students.center_id', $user->center_id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function update(User $user, Guardian $guardian): bool
    {
        if ($this->isAdmin($user)) return true;

        return in_array($user->role, ['supervisor', 'teacher'], true)
            && $guardian->students()->where('students.center_id', $user->center_id)->exists();
    }

    public function delete(User $user, Guardian $guardian): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true);
    }
}
