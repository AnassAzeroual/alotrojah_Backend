<?php

namespace App\Policies;

use App\Models\AcademicSeason;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class SeasonPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user) || $user->role === 'student';
    }

    public function view(User $user, AcademicSeason $season): bool
    {
        if ($this->isAdmin($user)) return true;
        if (! $this->isStaff($user) && $user->role !== 'student') return false;

        // Legacy shared seasons (center_id NULL) stay visible; center-owned
        // ones only to their own center (students carry center_id too).
        return $season->center_id === null || (int) $season->center_id === (int) $user->center_id;
    }

    public function manage(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true);
    }

    /** Structural calendar edits (terms/weeks/sessions). */
    public function manageCalendar(User $user): bool
    {
        return $this->manage($user);
    }

    /** Calendar reads: staff plus students (own group only, enforced in queries). */
    public function viewCalendar(User $user): bool
    {
        return $this->isStaff($user) || $user->role === 'student';
    }

    public function delete(User $user, AcademicSeason $season): bool
    {
        return $this->isAdmin($user);
    }
}
