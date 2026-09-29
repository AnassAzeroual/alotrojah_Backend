<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionScore extends Model
{
    protected $table = 'session_scores';
    const UPDATED_AT = null;
    protected $fillable = ['student_id','season_id','term_id','week_id','session_id','log_date','module_id','score','entered_by'];
    protected $casts = [
        'log_date' => 'date',
        'score' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function module(): BelongsTo { return $this->belongsTo(ScoringModule::class, 'module_id'); }

    public function session(): BelongsTo { return $this->belongsTo(Session::class, 'session_id'); }

    public function student(): BelongsTo { return $this->belongsTo(Student::class, 'student_id'); }
}
