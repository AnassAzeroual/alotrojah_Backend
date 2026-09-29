<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemorizationLog extends Model
{
    protected $table = 'memorization_logs';
    const UPDATED_AT = null;
    protected $fillable = ['student_id','season_id','term_id','week_id','session_id','log_date','log_mode','hizb_no','thumn_no','thumn_amount','hizb_from','hizb_to','surah_from','ayah_from','surah_to','ayah_to','created_by'];
    protected $casts = [
        'log_date' => 'date',
        'hizb_no' => 'decimal:2',
        'thumn_amount' => 'decimal:2',
        'hizb_from' => 'decimal:2',
        'hizb_to' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo { return $this->belongsTo(Session::class, 'session_id'); }

    public function student(): BelongsTo { return $this->belongsTo(Student::class, 'student_id'); }
}
