<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class StudentPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        // student lists narrow to own records in the controller
        return $this->isStaff($user) || $user->role === 'student';
    }

    public function view(User $user, Student $student): bool
    {
        if ($user->role === 'student') return (int) $student->user_id === (int) $user->id;

        return $this->sameCenter($user, $student->center_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor', 'teacher'], true);
    }

    public function update(User $user, Student $student): bool
    {
        // Supervisors touch only their own center's pupils (admin is global).
        return in_array($user->role, ['admin', 'supervisor'], true)
            && $this->sameCenter($user, $student->center_id)
            || ($user->role === 'teacher' && $this->sameCenter($user, $student->center_id));
    }

    public function delete(User $user, Student $student): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true)
            && $this->sameCenter($user, $student->center_id);
    }
}
