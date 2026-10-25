<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Level extends Model
{
    protected $table = 'levels';

    public $timestamps = false;

    protected $fillable = ['season_id', 'code', 'name_ar', 'sessions_per_week', 'thumn_per_session_label', 'thumn_per_session_value', 'thumn_per_week_value', 'ahzab_per_term', 'ahzab_per_dawra', 'duration_label', 'total_ahzab', 'center_id'];

    public function season(): BelongsTo
    {
        return $this->belongsTo(AcademicSeason::class, 'season_id');
    }

    public function studentLevels(): HasMany
    {
        return $this->hasMany(StudentLevel::class, 'level_id');
    }

    /** The set owned by one season. */
    public function scopeForSeason(Builder $q, int $seasonId): void
    {
        $q->where($this->getTable().'.season_id', $seasonId);
    }

    /**
     * The latest earlier season of the same center lane that actually owns
     * levels. A center's first season has none, so it starts empty.
     */
    public static function previousSeasonFor(AcademicSeason $season): ?AcademicSeason
    {
        $previousId = DB::table('levels')
            ->join('academic_seasons', 'academic_seasons.id', '=', 'levels.season_id')
            ->where('levels.season_id', '<', $season->id)
            ->when(
                $season->center_id === null,
                fn ($q) => $q->whereNull('academic_seasons.center_id'),
                fn ($q) => $q->where('academic_seasons.center_id', $season->center_id),
            )
            ->max('levels.season_id');

        return $previousId === null ? null : AcademicSeason::find($previousId);
    }

    /**
     * Copy a previous season's level set into a season (idempotent: a season
     * that already owns rows is left untouched). Clones keep the source
     * `code`, so `L1` means the same level in every season. There is no static
     * template fallback: a first season starts with zero levels and the admin
     * creates them.
     *
     * @return int rows created
     */
    public static function cloneIntoSeason(AcademicSeason $season): int
    {
        if (static::where('season_id', $season->id)->exists()) {
            return 0;
        }

        $source = static::previousSeasonFor($season);
        if ($source === null) {
            return 0;
        }

        $made = 0;
        foreach (static::where('season_id', $source->id)->orderBy('id')->get() as $row) {
            static::create([
                'season_id' => $season->id,
                'center_id' => $season->center_id,
                'code' => $row->code,
                'name_ar' => $row->name_ar,
                'sessions_per_week' => $row->sessions_per_week,
                'thumn_per_session_label' => $row->thumn_per_session_label,
                'thumn_per_session_value' => $row->thumn_per_session_value,
                'thumn_per_week_value' => $row->thumn_per_week_value,
                'ahzab_per_term' => $row->ahzab_per_term,
                'ahzab_per_dawra' => $row->ahzab_per_dawra,
                'duration_label' => $row->duration_label,
                'total_ahzab' => $row->total_ahzab,
            ]);
            $made++;
        }

        return $made;
    }

    /**
     * Ahzab per term from a season calendar: only `study` weeks carry new
     * hifz, `review` (murajaa) weeks are excluded.
     *
     * @param  Collection<int, Term>  $terms  terms with `weeks` loaded
     * @return list<array{term_id:int, term_number:int, name_ar:string, study_weeks:int, review_weeks:int, ahzab:float}>
     */
    public function termGoals(Collection $terms): array
    {
        return $terms->map(function (Term $term): array {
            $study = $term->weeks->where('week_type', 'study')->count();

            return [
                'term_id' => (int) $term->id,
                'term_number' => (int) $term->term_number,
                'name_ar' => $term->name_ar,
                'study_weeks' => $study,
                'review_weeks' => $term->weeks->where('week_type', 'review')->count(),
                'ahzab' => round($study * (int) $this->sessions_per_week * (float) $this->thumn_per_session_value / 8, 2),
            ];
        })->values()->all();
    }

    protected $casts = [
        'thumn_per_session_value' => 'float',
        'thumn_per_week_value' => 'float',
        'ahzab_per_term' => 'float',
        'ahzab_per_dawra' => 'float',
    ];

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'level_id');
    }
}
