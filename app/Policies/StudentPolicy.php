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
        // student/guardian lists narrow to own records in the controller
        return $this->isStaff($user) || in_array($user->role, ['guardian', 'student'], true);
    }

    public function view(User $user, Student $student): bool
    {
        if ($user->role === 'guardian') return true; // narrowed to own children in controller
        if (in_array($user->role, ['student'], true)) return (int) $student->user_id === (int) $user->id;

        return $this->sameCenter($user, $student->center_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor', 'teacher'], true);
    }

    public function update(User $user, Student $student): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true)
            || ($user->role === 'teacher' && $this->sameCenter($user, $student->center_id));
    }

    public function delete(User $user, Student $student): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true)
            && $this->sameCenter($user, $student->center_id);
    }
}
