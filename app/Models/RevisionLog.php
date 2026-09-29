<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevisionLog extends Model
{
    protected $table = 'revision_logs';
    public $timestamps = false;
    protected $fillable = ['student_id','season_id','term_id','week_id','session_id','log_date','hizb_from','hizb_to','murajaa_score','entered_by'];
    protected $casts = [
        'log_date' => 'date',
        'hizb_from' => 'decimal:2',
        'hizb_to' => 'decimal:2',
        'murajaa_score' => 'decimal:2',
    ];

    public function enteredBy(): BelongsTo { return $this->belongsTo(User::class, 'entered_by'); }

    public function session(): BelongsTo { return $this->belongsTo(Session::class, 'session_id'); }

    public function student(): BelongsTo { return $this->belongsTo(Student::class, 'student_id'); }
}
