<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Waiting-room staging row. Accepted requests are copied into users
 * (+students for students) then deleted; canceled ones are deleted.
 */
class RegistrationRequest extends Model
{
    protected $table = 'registration_requests';

    const CREATED_AT = 'requested_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'full_name', 'email', 'password_hash', 'role', 'teacher_type',
        'phone', 'birth_date', 'gender',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'birth_date' => 'date',
        'requested_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Same invariant as users (chk_regrequests_teacher_type): a waiting
        // student/supervisor/board row carries no teacher type.
        static::saving(function (RegistrationRequest $request): void {
            if ($request->role !== 'teacher') {
                $request->teacher_type = null;
            }
        });
    }
}
