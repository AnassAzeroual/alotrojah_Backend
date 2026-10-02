<?php

namespace App\Policies;

use App\Models\SessionScore;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class SessionScorePolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user) || $user->role === 'student';
    }

    public function view(User $user, SessionScore $score): bool
    {
        if ($this->isAdmin($user)) return true;

        return (int) $score->student?->center_id === (int) $user->center_id;
    }

    /** Bulk entry gate. Murajaa teachers are blocked inside the service too. */
    public function manage(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor', 'teacher'], true);
    }
}
