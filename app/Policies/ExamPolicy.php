<?php

namespace App\Policies;

use App\Models\Exam;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

class ExamPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user) || in_array($user->role, ['guardian', 'student'], true);
    }

    public function view(User $user, Exam $exam): bool
    {
        if ($this->isAdmin($user)) return true;

        return (int) $exam->student?->center_id === (int) $user->center_id;
    }

    public function manage(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor', 'teacher', 'examiner'], true);
    }

    public function delete(User $user, Exam $exam): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true);
    }
}
