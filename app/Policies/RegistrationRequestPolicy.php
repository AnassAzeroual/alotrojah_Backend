<?php

namespace App\Policies;

use App\Models\RegistrationRequest;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class RegistrationRequestPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function accept(User $user, RegistrationRequest $model): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, RegistrationRequest $model): bool
    {
        return $this->isAdmin($user);
    }
}
