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
        return $this->isStaff($user);
    }

    public function view(User $user, AcademicSeason $season): bool
    {
        return $this->isStaff($user);
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

    public function delete(User $user, AcademicSeason $season): bool
    {
        return $this->isAdmin($user);
    }
}
