<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MurajaaReview extends Model
{
    protected $table = 'murajaa_reviews';
    const UPDATED_AT = null;
    protected $fillable = ['student_id','season_id','term_id','week_from','week_to','session_id','hizb_from','hizb_to','surah_from','ayah_from','surah_to','ayah_to','score','entered_by','reviewed_at'];
    protected $casts = [
        'hizb_from' => 'decimal:2',
        'hizb_to' => 'decimal:2',
        'score' => 'decimal:2',
        'reviewed_at' => 'date',
        'created_at' => 'datetime',
    ];

    public function enteredBy(): BelongsTo { return $this->belongsTo(User::class, 'entered_by'); }

    public function session(): BelongsTo { return $this->belongsTo(Session::class, 'session_id'); }

    public function student(): BelongsTo { return $this->belongsTo(Student::class, 'student_id'); }
}
