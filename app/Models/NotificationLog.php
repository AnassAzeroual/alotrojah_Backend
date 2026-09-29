<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $table = 'notifications_log';
    public $timestamps = false;
    protected $fillable = ['recipient_phone','channel','message','status','sent_by','sent_at'];
    protected $casts = [
        'sent_at' => 'datetime',
    ];
}
