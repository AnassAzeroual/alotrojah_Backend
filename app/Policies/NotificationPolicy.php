<?php

namespace App\Policies;

use App\Models\NotificationLog;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class NotificationPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, NotificationLog $notification): bool
    {
        if ($this->isAdmin($user)) return true;

        return (int) $notification->sentBy?->center_id === (int) $user->center_id;
    }

    public function create(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function update(User $user, NotificationLog $notification): bool
    {
        return $this->isAdmin($user)
            || (int) $notification->sent_by === (int) $user->id
            || ($user->role === 'supervisor' && (int) $notification->sentBy?->center_id === (int) $user->center_id);
    }

    public function delete(User $user, NotificationLog $notification): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true);
    }
}
