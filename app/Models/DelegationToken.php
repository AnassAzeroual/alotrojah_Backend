<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DelegationToken extends Model
{
    protected $table = 'delegation_tokens';
    const UPDATED_AT = null;
    protected $fillable = ['group_id','granter_teacher_id','token','duration_minutes','expires_at','used_by_teacher_id','used_at','is_revoked'];
    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
        'is_revoked' => 'boolean',
    ];

    public function granterTeacher(): BelongsTo { return $this->belongsTo(User::class, 'granter_teacher_id'); }

    public function group(): BelongsTo { return $this->belongsTo(Group::class, 'group_id'); }

    public function usedByTeacher(): BelongsTo { return $this->belongsTo(User::class, 'used_by_teacher_id'); }
}
