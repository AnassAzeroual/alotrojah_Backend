<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $table = 'announcements';
    const UPDATED_AT = null;
    protected $fillable = ['author_id','audience','group_id','title','body'];
    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }

    public function group(): BelongsTo { return $this->belongsTo(Group::class, 'group_id'); }
}
