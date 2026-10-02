<?php

namespace App\Services;

use App\Models\DelegationToken;
use App\Models\Group;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Q7: responsible teacher opens time-boxed entry access for another teacher.
 * Link travels via WhatsApp (wa.me); redeem validates expiry/revocation.
 */
class DelegationService
{
    public function generate(Group $group, User $granter, int $minutes = 30): DelegationToken
    {
        abort_unless(in_array($minutes, [15, 30, 60, 120], true), 422, 'Invalid duration.');

        return DelegationToken::create([
            'group_id' => $group->id,
            'granter_teacher_id' => $granter->id,
            'token' => Str::random(64),
            'duration_minutes' => $minutes,
            'expires_at' => Carbon::now()->addMinutes($minutes),
        ]);
    }

    /** @throws ValidationException */
    public function redeem(string $token, User $teacher): DelegationToken
    {
        // request-level guard lives in RedeemDelegationRequest; kept here for future callers
        abort_unless($teacher->role === 'teacher', 403, 'Only teachers can redeem delegation links.');
        $d = DelegationToken::where('token', $token)->first()
            ?? throw ValidationException::withMessages(['token' => 'Invalid link.']);
        abort_if($d->is_revoked, 410, 'Link revoked.');
        abort_if(Carbon::now()->greaterThan($d->expires_at), 410, 'Link expired.');

        if ($d->used_by_teacher_id === null) {
            $d->update(['used_by_teacher_id' => $teacher->id, 'used_at' => Carbon::now()]);
        }
        abort_unless($d->used_by_teacher_id === $teacher->id, 403, 'Link bound to another teacher.');

        return $d;
    }

    public function shareLink(DelegationToken $d): string
    {
        $base = rtrim(config('app.frontend_url', config('app.url')), '/');

        return $base.'/delegate?token='.$d->token;
    }

    /** Cross-center entry allowed when the teacher redeemed a live token for the student's group. */
    public function canActAs(User $teacher, Student $student): bool
    {
        if ($student->group_id === null) return false;
        $key = $teacher->id.':'.$student->group_id;
        if (array_key_exists($key, $this->actCache)) return $this->actCache[$key];

        return $this->actCache[$key] = DelegationToken::where('group_id', $student->group_id)
            ->where('used_by_teacher_id', $teacher->id)
            ->where('is_revoked', false)
            ->where('expires_at', '>', Carbon::now())
            ->exists();
    }

    private array $actCache = [];
}
