<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Levels become per center *and* per season (owner-directed 2026-10-24).
//
// A season already carries `center_id`, so a level that belongs to a season
// belongs to exactly one center. `levels.season_id` NULL keeps the original
// shared rows as the seed/template set: every new season is cloned from the
// previous season of the same center, falling back to these templates.
//
// Pupil placement is per season (`student_levels`). `students.level_id` stays
// as a compatibility pointer to the current season's row; the new table is the
// source of truth for history and for per-season reporting.
//
// Phase 1 is additive on purpose: `levels.center_id` and the copy-on-write
// readers stay until the controllers are switched over (phase 2), so the
// existing suite keeps passing between the two migrations.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->unsignedBigInteger('season_id')->nullable()->after('id')
                ->comment('NULL = template/seed set; otherwise this season owns the set');
            $table->index('season_id', 'idx_levels_season');
            $table->foreign('season_id', 'fk_levels_season')
                ->references('id')->on('academic_seasons')->cascadeOnDelete();
        });

        // Codes stay L{id}; uniqueness is now per season instead of per center.
        Schema::table('levels', function (Blueprint $table) {
            $table->dropUnique('uq_levels_code_center');
            $table->unique(['season_id', 'code'], 'uq_levels_season_code');
        });

        Schema::create('student_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedInteger('level_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['student_id', 'season_id'], 'uq_student_levels_student_season');
            $table->index(['season_id', 'level_id'], 'idx_student_levels_season_level');
            $table->foreign('student_id', 'fk_student_levels_student')
                ->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('season_id', 'fk_student_levels_season')
                ->references('id')->on('academic_seasons')->cascadeOnDelete();
            $table->foreign('level_id', 'fk_student_levels_level')
                ->references('id')->on('levels')->restrictOnDelete();
        });

        $this->backfill();
        $this->refreshDashboardView();
    }

    public function down(): void
    {
        $this->restoreLegacyDashboardView();

        // Point pupils back at a template/override row with the same code.
        foreach (DB::table('students')->orderBy('id')->get() as $student) {
            $level = DB::table('levels')->where('id', $student->level_id)->first();
            if ($level === null || $level->season_id === null) {
                continue;
            }
            $fallback = DB::table('levels')
                ->whereNull('season_id')->where('code', $level->code)
                ->when($level->center_id !== null,
                    fn ($q) => $q->where(fn ($q2) => $q2->whereNull('center_id')->orWhere('center_id', $level->center_id)),
                    fn ($q) => $q->whereNull('center_id'))
                ->orderByRaw('center_id IS NULL')
                ->value('id');
            if ($fallback !== null) {
                DB::table('students')->where('id', $student->id)->update(['level_id' => $fallback]);
            }
        }

        Schema::dropIfExists('student_levels');

        // Clones did not exist before this migration; drop the unreferenced ones.
        $referenced = DB::table('students')->pluck('level_id');
        DB::table('levels')->whereNotNull('season_id')
            ->when($referenced->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $referenced))
            ->delete();

        Schema::table('levels', function (Blueprint $table) {
            $table->dropUnique('uq_levels_season_code');
            $table->unique(['code', 'center_id'], 'uq_levels_code_center');
        });

        Schema::table('levels', function (Blueprint $table) {
            $table->dropForeign('fk_levels_season');
            $table->dropIndex('idx_levels_season');
            $table->dropColumn('season_id');
        });
    }

    /**
     * Clone the effective level set into every existing season, then place
     * every pupil in their center's current season. A fresh install has no
     * seasons, so this is a no-op there; prod/verify get the real migration.
     */
    private function backfill(): void
    {
        $seasons = DB::table('academic_seasons')->orderBy('id')->get();
        if ($seasons->isEmpty()) {
            return;
        }

        $templates = DB::table('levels')
            ->whereNull('season_id')->whereNull('center_id')
            ->orderBy('id')->get();

        $overrides = DB::table('levels')
            ->whereNull('season_id')->whereNotNull('center_id')
            ->orderBy('id')->get()->groupBy('center_id');

        // season_id => [code => new level id]
        $map = [];

        foreach ($seasons as $season) {
            $previous = DB::table('academic_seasons')
                ->where('center_id', $season->center_id)
                ->where('id', '<', $season->id)
                ->orderByDesc('id')->first();

            if ($previous !== null && ! empty($map[$previous->id])) {
                $source = DB::table('levels')
                    ->where('season_id', $previous->id)
                    ->orderBy('id')->get()
                    ->map(fn ($row) => ['code' => $row->code, 'row' => $row]);
            } else {
                $effective = [];
                foreach ($templates as $row) {
                    $effective[$row->code] = $row;
                }
                $centerOverrides = $season->center_id !== null
                    ? ($overrides[$season->center_id] ?? collect())
                    : collect();
                foreach ($centerOverrides as $row) {
                    $effective[$row->code] = $row;
                }
                ksort($effective);
                $source = collect($effective)
                    ->map(fn ($row) => ['code' => $row->code, 'row' => $row]);
            }

            foreach ($source as $item) {
                $row = $item['row'];
                // The clone keeps the source code: `L1` must mean the same
                // level in every season (codes are unique per season, so the
                // same code can repeat across seasons). That keeps reports,
                // filters and the UI stable across the yearly clone.
                $newId = DB::table('levels')->insertGetId([
                    'season_id' => $season->id,
                    'center_id' => $season->center_id,
                    'code' => $item['code'],
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
                $map[$season->id][$item['code']] = $newId;
            }
        }

        // Current season per center: is_current wins, latest season otherwise.
        $current = [];
        foreach ($seasons as $season) {
            $key = (string) $season->center_id;
            if ($season->is_current || ! array_key_exists($key, $current)) {
                $current[$key] = $season->id;
            }
        }

        foreach (DB::table('students')->orderBy('id')->get() as $student) {
            // Own-center season wins; legacy shared (center_id NULL) is the fallback.
            $seasonId = $current[(string) $student->center_id] ?? $current[''] ?? null;
            if ($seasonId === null || empty($map[$seasonId])) {
                continue;
            }

            $oldCode = DB::table('levels')->where('id', $student->level_id)->value('code');
            $newLevel = $oldCode !== null ? ($map[$seasonId][$oldCode] ?? null) : null;
            if ($newLevel === null) {
                $newLevel = reset($map[$seasonId]);
            }

            DB::table('student_levels')->insertOrIgnore([
                'student_id' => $student->id,
                'season_id' => $seasonId,
                'level_id' => $newLevel,
                'created_at' => now(),
            ]);
            DB::table('students')->where('id', $student->id)
                ->update(['level_id' => $newLevel]);
        }
    }

    /** Per-season placement wins; the legacy pointer is the fallback. */
    private function refreshDashboardView(): void
    {
        DB::statement('CREATE OR REPLACE VIEW `v_season_dashboard` AS
SELECT s.id AS student_id, s.full_name, COALESCE(sl.level_id, s.level_id) AS level_id, s.group_id,
  sp.season_id,
  (SELECT COALESCE(SUM(m.thumn_amount),0) FROM memorization_logs m
    WHERE m.student_id=s.id AND m.season_id=sp.season_id) AS season_thumn,
  (SELECT ROUND(AVG(t.total_score),2) FROM v_session_totals t
    WHERE t.student_id=s.id AND t.season_id=sp.season_id) AS season_avg_score,
  (SELECT ROUND(AVG(x.avg_score),2) FROM v_separate_module_avgs x
    WHERE x.student_id=s.id AND x.season_id=sp.season_id AND x.module_code=\'sarraj\') AS season_avg_sarraj,
  (SELECT ROUND(100*SUM(a.status IN (\'present\',\'late\'))/COUNT(*),1) FROM attendance a
    WHERE a.student_id=s.id AND a.season_id=sp.season_id) AS season_attendance_pct,
  r.overall_avg AS season_overall_avg, r.honor_flag
FROM students s
JOIN (SELECT DISTINCT student_id, season_id FROM term_plans
      UNION SELECT DISTINCT student_id, season_id FROM memorization_logs
      UNION SELECT DISTINCT student_id, season_id FROM attendance) sp ON sp.student_id=s.id
LEFT JOIN student_levels sl ON sl.student_id=s.id AND sl.season_id=sp.season_id
LEFT JOIN season_results r ON r.student_id=s.id AND r.season_id=sp.season_id');
    }

    private function restoreLegacyDashboardView(): void
    {
        DB::statement('CREATE OR REPLACE VIEW `v_season_dashboard` AS
SELECT s.id AS student_id, s.full_name, s.level_id, s.group_id,
  sp.season_id,
  (SELECT COALESCE(SUM(m.thumn_amount),0) FROM memorization_logs m
    WHERE m.student_id=s.id AND m.season_id=sp.season_id) AS season_thumn,
  (SELECT ROUND(AVG(t.total_score),2) FROM v_session_totals t
    WHERE t.student_id=s.id AND t.season_id=sp.season_id) AS season_avg_score,
  (SELECT ROUND(AVG(x.avg_score),2) FROM v_separate_module_avgs x
    WHERE x.student_id=s.id AND x.season_id=sp.season_id AND x.module_code=\'sarraj\') AS season_avg_sarraj,
  (SELECT ROUND(100*SUM(a.status IN (\'present\',\'late\'))/COUNT(*),1) FROM attendance a
    WHERE a.student_id=s.id AND a.season_id=sp.season_id) AS season_attendance_pct,
  r.overall_avg AS season_overall_avg, r.honor_flag
FROM students s
JOIN (SELECT DISTINCT student_id, season_id FROM term_plans
      UNION SELECT DISTINCT student_id, season_id FROM memorization_logs
      UNION SELECT DISTINCT student_id, season_id FROM attendance) sp ON sp.student_id=s.id
LEFT JOIN season_results r ON r.student_id=s.id AND r.season_id=sp.season_id');
    }
};
