<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\Student;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class GroupPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        // Students see their own group only (narrowed in the controller).
        return $this->isStaff($user) || $user->role === 'student';
    }

    public function view(User $user, Group $group): bool
    {
        // Students open only the group their pupil record belongs to.
        if ($user->role === 'student') {
            return $this->sameCenter($user, $group->center_id)
                && Student::where('user_id', $user->id)->where('group_id', $group->id)->exists();
        }

        return $this->sameCenter($user, $group->center_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true);
    }

    public function update(User $user, Group $group): bool
    {
        // Supervisors touch only their own center's groups (admin is global).
        return in_array($user->role, ['admin', 'supervisor'], true)
            && $this->sameCenter($user, $group->center_id)
            || ($user->role === 'teacher' && (int) $group->teacher_id === (int) $user->id);
    }

    /**
     * §2.6: groups are never deleted (typo groups are deactivated). Admins get
     * an explicit refusal message from the controller; everyone else 403s here.
     */
    public function delete(User $user, Group $group): bool
    {
        return $this->isAdmin($user);
    }

    public function delegate(User $user, Group $group): bool
    {
        return $this->isAdmin($user) || (int) $group->teacher_id === (int) $user->id;
    }

    /** Alias used by the delegation form request. */
    public function generate(User $user, Group $group): bool
    {
        return $this->delegate($user, $group);
    }
}
