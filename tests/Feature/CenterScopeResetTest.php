<?php

namespace Tests\Feature;

use App\Models\AcademicSeason;
use App\Models\Center;
use App\Models\Level;
use App\Models\ScoringModule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeasonLevelFixtures;
use Tests\TestCase;

/**
 * T2 reset: DELETE <resource>/reset removes only that scope's overrides
 * (scoring: ?center_id=X) or restores a season's level set from the previous
 * season (?season_id=X), all-or-nothing refusal with MODULE_HAS_SCORES /
 * LEVEL_IN_USE when referenced and LEVEL_SOURCE_MISSING without a source.
 */
class CenterScopeResetTest extends TestCase
{
    use SeasonLevelFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_scoring_reset_deletes_only_that_centers_overrides(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->putJson('/api/v1/scoring-modules', [
            'center_id' => 1,
            'modules' => [
                ['code' => 'hifz', 'max_points' => 10],
                ['code' => 'mowathaba', 'max_points' => 8],
                ['code' => 'tajwid', 'max_points' => 2],
            ],
        ])->assertOk();
        $this->assertSame(5, ScoringModule::where('center_id', 1)->count());

        $this->deleteJson('/api/v1/scoring-modules/reset?center_id=1')
            ->assertOk()->assertJsonPath('data.deleted', 5);

        $this->assertSame(0, ScoringModule::where('center_id', 1)->count());
        $this->assertSame(5, ScoringModule::whereNull('center_id')->count());
        $this->getJson('/api/v1/scoring-check?center_id=1')->assertOk()
            ->assertJsonPath('data.total', 20);
    }

    public function test_scoring_reset_refused_when_scores_reference_overrides(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->putJson('/api/v1/scoring-modules', [
            'center_id' => 1,
            'modules' => [
                ['code' => 'hifz', 'max_points' => 10],
                ['code' => 'mowathaba', 'max_points' => 8],
                ['code' => 'tajwid', 'max_points' => 2],
            ],
        ])->assertOk();
        $acct = User::create([
            'full_name' => 'Reset Guard Account', 'email' => 'reset-guard-pupil@example.org',
            'password_hash' => 'x', 'role' => 'student', 'center_id' => 1, 'is_active' => true,
        ]);
        $pupil = $this->postJson('/api/v1/students', [
            'full_name' => 'Reset Guard Pupil', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 1, 'group_id' => 1,
            'level_id' => $this->currentLevelId(1),
            'user_id' => $acct->id,
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/scores/bulk', [
            'session_id' => 1,
            'records' => [['student_id' => $pupil, 'module_code' => 'hifz', 'score' => 10]],
        ])->assertCreated();

        $this->deleteJson('/api/v1/scoring-modules/reset?center_id=1')
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'MODULE_HAS_SCORES');
        // All-or-nothing: the override set is untouched.
        $this->assertSame(5, ScoringModule::where('center_id', 1)->count());
    }

    public function test_scoring_reset_noop_without_overrides(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->assertSame(0, ScoringModule::where('center_id', 2)->count());
        $this->deleteJson('/api/v1/scoring-modules/reset?center_id=2')
            ->assertOk()->assertJsonPath('data.deleted', 0);
    }

    public function test_levels_reset_restores_the_season_set_from_the_previous_season(): void
    {
        $this->actingAs(User::find(1), 'api');
        $center = Center::create(['name' => 'Reset Center '.uniqid()]);
        $source = AcademicSeason::create([
            'name' => 'Reset Source '.uniqid(),
            'start_date' => '2025-09-01',
            'total_weeks' => 0,
            'total_sessions' => 0,
            'is_current' => false,
            'center_id' => $center->id,
        ]);
        $this->seedSeasonLevels($source);

        $season = AcademicSeason::create([
            'name' => 'Reset Season '.uniqid(),
            'start_date' => '2026-09-01',
            'total_weeks' => 0,
            'total_sessions' => 0,
            'is_current' => false,
            'center_id' => $center->id,
        ]);
        Level::cloneIntoSeason($season);
        $l1 = Level::where('season_id', $season->id)->where('code', 'L1')->firstOrFail();
        $this->putJson("/api/v1/levels/{$l1->id}", ['sessions_per_week' => 5])->assertOk();

        $this->deleteJson("/api/v1/levels/reset?season_id={$season->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', 3)
            ->assertJsonPath('data.created', 3);

        $fresh = Level::where('season_id', $season->id)->where('code', 'L1')->firstOrFail();
        $this->assertSame(3, (int) $fresh->sessions_per_week);
        $this->assertNotSame($l1->id, $fresh->id);
    }

    public function test_levels_reset_refuses_a_first_season_without_a_source(): void
    {
        $this->actingAs(User::find(1), 'api');
        $center = Center::create(['name' => 'First Season Center '.uniqid()]);
        $season = AcademicSeason::create([
            'name' => 'First Season '.uniqid(),
            'start_date' => '2026-09-01',
            'total_weeks' => 0,
            'total_sessions' => 0,
            'is_current' => false,
            'center_id' => $center->id,
        ]);
        $this->seedSeasonLevels($season);

        $this->deleteJson("/api/v1/levels/reset?season_id={$season->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'LEVEL_SOURCE_MISSING');
        // All-or-nothing: a reset that cannot copy does not delete.
        $this->assertSame(3, Level::where('season_id', $season->id)->count());
    }

    public function test_levels_reset_refused_when_pupils_reference_the_set(): void
    {
        $this->actingAs(User::find(1), 'api');
        // The seeded shared season has placements, so its set is protected.
        $this->deleteJson('/api/v1/levels/reset?season_id=1')
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'LEVEL_IN_USE');
        $this->assertSame(3, Level::where('season_id', 1)->count());
    }

    public function test_reset_requires_admin_and_season(): void
    {
        $this->actingAs(User::find(2), 'api');
        $this->deleteJson('/api/v1/scoring-modules/reset?center_id=1')->assertForbidden();
        $this->deleteJson('/api/v1/levels/reset?season_id=1')->assertForbidden();

        $this->actingAs(User::find(1), 'api');
        $this->deleteJson('/api/v1/scoring-modules/reset')->assertStatus(422);
        $this->deleteJson('/api/v1/levels/reset')->assertStatus(422);
    }
}
