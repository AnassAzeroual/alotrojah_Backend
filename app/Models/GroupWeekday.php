<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupWeekday extends Model
{
    protected $table = 'group_weekdays';

    public $timestamps = false;

    protected $fillable = ['group_id', 'weekday'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}
