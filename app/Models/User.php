<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

/**
 * Auth identity. Password lives in `password_hash` (legacy schema),
 * so getAuthPassword() is overridden. Roles/center travel as JWT claims.
 */
class User extends Authenticatable implements JWTSubject
{
    protected $table = 'users';

    const UPDATED_AT = null;

    protected $fillable = [
        'full_name', 'email', 'role', 'phone', 'center_id', 'teacher_type', 'is_active',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->role,
            'center_id' => $this->center_id,
            'teacher_type' => $this->teacher_type,
        ];
    }

    public function scopeForCenter($q, int $centerId): void
    {
        $q->where('users.center_id', $centerId);
    }

    public function scopeActive($q): void
    {
        $q->where('users.is_active', true);
    }

    public function taughtGroups(): HasMany { return $this->hasMany(Group::class, 'teacher_id'); }
    public function center(): BelongsTo { return $this->belongsTo(Center::class, 'center_id'); }
    public function authoredAnnouncements(): HasMany { return $this->hasMany(Announcement::class, 'author_id'); }
}
