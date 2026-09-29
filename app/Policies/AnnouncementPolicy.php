<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class AnnouncementPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return true; // any authenticated user; rows filtered by visibility scope
    }

    public function view(User $user, Announcement $announcement): bool
    {
        return Announcement::visibleTo($user)->where('announcements.id', $announcement->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $this->isAdmin($user) || (int) $announcement->author_id === (int) $user->id;
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $this->isAdmin($user)
            || (int) $announcement->author_id === (int) $user->id
            || ($user->role === 'supervisor' && (int) $announcement->author?->center_id === (int) $user->center_id);
    }
}
