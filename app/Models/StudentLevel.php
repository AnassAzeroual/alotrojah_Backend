<?php

namespace App\Models;

use App\Models\Scopes\CenterScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One pupil's level in one season. Source of truth for per-season placement;
 * `students.level_id` mirrors the *current* season's row for compatibility
 * (policies, lists and the entry prefill keep reading the pointer).
 *
 * Season rollover: activating a new season carries every pupil's current level
 * into the new season's cloned set by `code` and re-points `students.level_id`
 * at that clone, so a pupil never changes level just because a season changed.
 */
class StudentLevel extends Model
{
    protected $table = 'student_levels';

    public $timestamps = false;

    protected $fillable = ['student_id', 'season_id', 'level_id'];

    protected $casts = ['created_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(AcademicSeason::class, 'season_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'level_id');
    }

    /**
     * Place a pupil in their center's current season.
     *
     * The input level may be a template row (shared seed set) or a row owned
     * by another season: it is mapped onto the current season's clone by
     * `code` (L1 means the same level every season). The placement is
     * upserted and the mapped season-level id is returned so callers can keep
     * `students.level_id` in step. Returns null when the center has no season
     * yet (nothing to place against) or the season has no level with that
     * code.
     */
    public static function place(Student $student, int $levelId): ?int
    {
        $season = AcademicSeason::currentFor(
            $student->center_id === null ? null : (int) $student->center_id
        );
        if ($season === null) {
            return null;
        }

        return static::placeInSeason($student, $levelId, $season);
    }

    /**
     * Map a level id onto one specific season's set and upsert the placement.
     *
     * Returns the season-owned level id, or null when the season does not own
     * a level with the source `code` (never writes a cross-season pointer).
     */
    public static function placeInSeason(Student $student, int $levelId, AcademicSeason $season): ?int
    {
        $source = Level::find($levelId);
        if ($source === null) {
            return null;
        }

        $target = $source->season_id !== null && (int) $source->season_id === (int) $season->id
            ? (int) $source->id
            : (int) Level::forSeason($season->id)->where('code', $source->code)->value('id');
        if ($target <= 0) {
            return null;
        }

        static::updateOrCreate(
            ['student_id' => $student->id, 'season_id' => $season->id],
            ['level_id' => $target],
        );

        return $target;
    }

    /**
     * Copy each lane pupil's current level into `$season` (missing rows only).
     *
     * Used when a season is activated: the new season's level set is a fresh
     * clone, so every pupil needs a placement row there. The pupil's own row is
     * never overwritten — a placement already recorded for the season wins.
     *
     * @return int rows inserted (0 when the season has no pupils or levels)
     */
    public static function carryForward(AcademicSeason $season): int
    {
        $pupils = self::pupilsFor($season);
        if ($pupils->isEmpty()) {
            return 0;
        }

        /** @var array<string, int> $targets code => level id */
        $targets = Level::forSeason($season->id)->pluck('id', 'code')->all();
        if ($targets === []) {
            return 0;
        }

        $pupilIds = $pupils->pluck('id')->all();
        /** @var array<int, string> $sourceCodes level id => code */
        $sourceCodes = Level::whereIn('id', $pupils->pluck('level_id')->filter()->unique()->all())
            ->pluck('code', 'id')->all();

        // Fallback for a pointer that no longer resolves (level deleted):
        // the pupil's most recent placement still carries the last known code.
        $placements = [];
        $rows = static::whereIn('student_id', $pupilIds)
            ->orderByDesc('season_id')
            ->get(['student_id', 'season_id', 'level_id']);
        foreach ($rows as $row) {
            $placements[(int) $row->student_id] ??= $row;
        }
        $fallbackCodes = Level::whereIn('id', collect($placements)->pluck('level_id')->unique()->all())
            ->pluck('code', 'id')->all();

        $insert = [];
        foreach ($pupils as $pupil) {
            $code = $sourceCodes[$pupil->level_id] ?? null;
            if ($code === null && isset($placements[(int) $pupil->id])) {
                $code = $fallbackCodes[$placements[(int) $pupil->id]->level_id] ?? null;
            }
            if ($code === null || ! isset($targets[$code])) {
                continue;
            }
            $insert[] = [
                'student_id' => (int) $pupil->id,
                'season_id' => (int) $season->id,
                'level_id' => (int) $targets[$code],
                'created_at' => now(),
            ];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('student_levels')->insertOrIgnore($chunk);
        }

        return count($insert);
    }

    /**
     * Make `$season` the pupils' placement of record: carry the missing rows
     * in, then point `students.level_id` at this season's level clone.
     *
     * Called inside the activation transaction (after `is_current` flips), so
     * `currentFor()` already resolves the new season. Pupils of other centers
     * are never touched: a shared (NULL-center) season only owns the centers
     * whose lane resolves to it.
     */
    public static function activate(AcademicSeason $season): void
    {
        static::carryForward($season);

        $pupilIds = self::pupilsFor($season)->pluck('id')->all();
        if ($pupilIds === []) {
            return;
        }

        $placements = static::where('season_id', $season->id)
            ->whereIn('student_id', $pupilIds)
            ->get(['student_id', 'level_id']);

        foreach ($placements->groupBy('level_id') as $levelId => $group) {
            Student::withoutGlobalScope(CenterScope::class)
                ->whereIn('id', $group->pluck('student_id')->all())
                ->update(['level_id' => (int) $levelId]);
        }
    }

    /**
     * Pupils the season owns: its center's pupils for a center-owned season;
     * for a shared (NULL-center) season, only pupils whose own center lane
     * actually resolves to it.
     *
     * @return Collection<int, Student>
     */
    private static function pupilsFor(AcademicSeason $season): Collection
    {
        $q = Student::withoutGlobalScope(CenterScope::class);

        if ($season->center_id !== null) {
            return $q->where('center_id', (int) $season->center_id)->get(['id', 'center_id', 'level_id']);
        }

        /** @var array<string, int|null> $current */
        $current = [];

        return $q->get(['id', 'center_id', 'level_id'])
            ->filter(function (Student $pupil) use ($season, &$current): bool {
                $key = (string) $pupil->center_id;
                if (! array_key_exists($key, $current)) {
                    $current[$key] = AcademicSeason::currentFor(
                        $pupil->center_id === null ? null : (int) $pupil->center_id
                    )?->id;
                }

                return $current[$key] !== null && (int) $current[$key] === (int) $season->id;
            })
            ->values();
    }
}
