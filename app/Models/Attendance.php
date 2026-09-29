<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $table = 'attendance';
    public $timestamps = false;
    protected $fillable = ['student_id','season_id','term_id','week_id','session_id','status','marked_by','marked_at','notes'];
    protected $casts = [
        'marked_at' => 'datetime',
    ];

    public function session(): BelongsTo { return $this->belongsTo(Session::class, 'session_id'); }

    public function student(): BelongsTo { return $this->belongsTo(Student::class, 'student_id'); }
}
