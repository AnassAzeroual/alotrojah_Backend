<?php

namespace App\Policies;

use App\Models\User;

class AppearancePolicy
{
    /** App-wide appearance is edited by admins only; every role reads it. */
    public function manage(User $user): bool
    {
        return $user->role === 'admin';
    }
}
