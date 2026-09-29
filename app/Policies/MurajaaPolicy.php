<?php

namespace App\Policies;

use App\Models\MurajaaReview;
use App\Models\RevisionLog;
use App\Models\User;
use App\Policies\Concerns\CenterScoped;

/** Covers MurajaaReview + RevisionLog (review-teacher inputs). */
class MurajaaPolicy
{
    use CenterScoped;

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user) || in_array($user->role, ['guardian', 'student'], true);
    }

    public function view(User $user, MurajaaReview|RevisionLog $review): bool
    {
        if ($this->isAdmin($user)) return true;

        return (int) $review->student?->center_id === (int) $user->center_id;
    }

    public function manageReviews(User $user): bool
    {
        if (in_array($user->role, ['admin', 'supervisor'], true)) return true;

        return $user->role === 'teacher' && in_array($user->teacher_type, ['murajaa', 'both'], true);
    }

    public function delete(User $user, MurajaaReview|RevisionLog $review): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true);
    }
}
