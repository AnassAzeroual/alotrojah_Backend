<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property 'none'|'tashji3'|'intibah' $honor_flag
 */
class SeasonResult extends Model
{
    protected $table = 'season_results';

    public $timestamps = false;

    protected $fillable = ['student_id', 'season_id', 'total_memorized_label', 'total_memorized_thumn', 'hifz_total', 'murajaa_total', 'overall_avg', 'board_report', 'honor_flag'];

    protected $casts = [
        'total_memorized_thumn' => 'float',
        'hifz_total' => 'float',
        'murajaa_total' => 'float',
        'overall_avg' => 'float',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(AcademicSeason::class, 'season_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
