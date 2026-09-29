<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    protected $table = 'notifications_log';
    public $timestamps = false;
    protected $fillable = ['recipient_phone','channel','message','status','sent_by','sent_at'];
    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function sentBy(): BelongsTo { return $this->belongsTo(User::class, 'sent_by'); }
}
