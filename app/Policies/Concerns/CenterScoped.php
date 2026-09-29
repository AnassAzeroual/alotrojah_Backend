<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Center isolation invariant: staff see only their own center.
 * Admin (center_id NULL) is global. Guardian/student see own records (handled per-policy).
 */
trait CenterScoped
{
    protected function isAdmin(User $user): bool
    {
        return $user->role === 'admin';
    }

    protected function sameCenter(User $user, ?int $centerId): bool
    {
        if ($this->isAdmin($user)) return true;

        return $user->center_id !== null && $user->center_id === $centerId;
    }

    protected function isStaff(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor', 'teacher', 'board'], true);
    }
}
