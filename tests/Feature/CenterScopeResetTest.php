<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\ScoringModule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T2 reset-to-defaults: DELETE <resource>/reset?center_id=X removes only that
 * center's override rows (shared defaults untouched), all-or-nothing refusal
 * with the existing MODULE_HAS_SCORES / LEVEL_IN_USE codes when referenced.
 */
class CenterScopeResetTest extends TestCase
{
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
        $pupil = $this->postJson('/api/v1/students', [
            'full_name' => 'Reset Guard Pupil', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 1, 'group_id' => 1, 'level_id' => 1,
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

    public function test_levels_reset_deletes_only_that_centers_overrides(): void
    {
        $this->actingAs(User::find(1), 'api');
        $shared = Level::where('code', 'L1')->whereNull('center_id')->firstOrFail();
        $this->putJson("/api/v1/levels/{$shared->id}", [
            'sessions_per_week' => 5, 'center_id' => 1,
        ])->assertOk();
        $this->assertSame(3, Level::where('center_id', 1)->count());

        $this->deleteJson('/api/v1/levels/reset?center_id=1')
            ->assertOk()->assertJsonPath('data.deleted', 3);

        $this->assertSame(0, Level::where('center_id', 1)->count());
        $this->assertSame(3, Level::whereNull('center_id')->count());
    }

    public function test_levels_reset_refused_when_pupils_reference_overrides(): void
    {
        $this->actingAs(User::find(1), 'api');
        $shared = Level::where('code', 'L1')->whereNull('center_id')->firstOrFail();
        $this->putJson("/api/v1/levels/{$shared->id}", [
            'sessions_per_week' => 5, 'center_id' => 1,
        ])->assertOk();
        $override = Level::where('code', 'L1')->where('center_id', 1)->firstOrFail();
        $this->postJson('/api/v1/students', [
            'full_name' => 'Reset Guard Pupil', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 1, 'level_id' => $override->id,
            'group_id' => 1,
        ])->assertCreated();

        $this->deleteJson('/api/v1/levels/reset?center_id=1')
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'LEVEL_IN_USE');
        $this->assertSame(3, Level::where('center_id', 1)->count());
    }

    public function test_reset_requires_admin_and_center(): void
    {
        $this->actingAs(User::find(2), 'api');
        $this->deleteJson('/api/v1/scoring-modules/reset?center_id=1')->assertForbidden();
        $this->deleteJson('/api/v1/levels/reset?center_id=1')->assertForbidden();

        $this->actingAs(User::find(1), 'api');
        $this->deleteJson('/api/v1/scoring-modules/reset')->assertStatus(422);
        $this->deleteJson('/api/v1/levels/reset')->assertStatus(422);
    }
}
