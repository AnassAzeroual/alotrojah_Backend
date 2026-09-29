<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermPlan extends Model
{
    protected $table = 'term_plans';
    public $timestamps = false;
    protected $fillable = ['student_id','season_id','term_id','goal_text','plan_mode','start_hizb','end_hizb','plan_surah_from','plan_ayah_from','plan_surah_to','plan_ayah_to','expected_hifz_week_thumn','expected_hifz_term_ahzab','expected_hifz_season_ahzab','khatm_expected_at'];
    protected $casts = [
        'start_hizb' => 'decimal:2',
        'end_hizb' => 'decimal:2',
        'expected_hifz_week_thumn' => 'decimal:2',
        'expected_hifz_term_ahzab' => 'decimal:2',
        'expected_hifz_season_ahzab' => 'decimal:2',
    ];

    public function season(): BelongsTo { return $this->belongsTo(AcademicSeason::class, 'season_id'); }

    public function student(): BelongsTo { return $this->belongsTo(Student::class, 'student_id'); }

    public function term(): BelongsTo { return $this->belongsTo(Term::class, 'term_id'); }
}
