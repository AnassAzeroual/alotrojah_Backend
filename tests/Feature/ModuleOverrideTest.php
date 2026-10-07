<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\ScoringModule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Copy-on-write center sets: shared defaults stay untouched, overrides apply
 * per center, the 20-rule holds per set, and entry caps follow the override.
 */
class ModuleOverrideTest extends TestCase
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

    public function test_first_scoped_edit_clones_the_set(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->assertSame(0, ScoringModule::whereNotNull('center_id')->count());

        $this->putJson('/api/v1/scoring-modules', [
            'center_id' => 1,
            'modules' => [
                ['code' => 'hifz', 'max_points' => 10],
                ['code' => 'mowathaba', 'max_points' => 8],
                ['code' => 'tajwid', 'max_points' => 2],
            ],
        ])->assertOk();

        // Five C1 rows cloned; the five shared defaults are byte-identical.
        $this->assertSame(5, ScoringModule::where('center_id', 1)->count());
        $this->assertSame(5, ScoringModule::whereNull('center_id')->count());
        $this->assertSame(10.0, (float) ScoringModule::where('code', 'hifz')->where('center_id', 1)->value('max_points'));
        $this->assertSame(14.0, (float) ScoringModule::where('code', 'hifz')->whereNull('center_id')->value('max_points'));
    }

    public function test_twenty_rule_is_per_set(): void
    {
        $this->actingAs(User::find(1), 'api');
        // Break C1 only: 10+4+2 = 16.
        $this->putJson('/api/v1/scoring-modules', [
            'center_id' => 1,
            'modules' => [['code' => 'hifz', 'max_points' => 10]],
        ])->assertStatus(422);
        // The shared set still validates.
        $this->getJson('/api/v1/scoring-check')->assertOk()->assertJsonPath('data.total', 20);
        $this->getJson('/api/v1/scoring-check?center_id=1')->assertOk()->assertJsonPath('data.total', 20);
    }

    public function test_entry_cap_follows_the_override(): void
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
            'full_name' => 'Capped Pupil', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 1, 'group_id' => 1,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
        ])->assertCreated()->json('data.id');

        // C1 cap is 10 now (default would allow 14).
        $this->postJson('/api/v1/scores/bulk', [
            'session_id' => 1,
            'records' => [['student_id' => $pupil, 'module_code' => 'hifz', 'score' => 11]],
        ])->assertStatus(422);
        $this->postJson('/api/v1/scores/bulk', [
            'session_id' => 1,
            'records' => [['student_id' => $pupil, 'module_code' => 'hifz', 'score' => 10]],
        ])->assertCreated();
    }

    public function test_one_default_row_per_code(): void
    {
        $dupes = ScoringModule::selectRaw('code, COUNT(*) c')->whereNull('center_id')
            ->groupBy('code')->havingRaw('COUNT(*) > 1')->count();
        $this->assertSame(0, $dupes);
        $this->assertSame(3, DB::table('levels')->whereNull('center_id')->count());
    }

    public function test_levels_feed_serves_effective_set(): void
    {
        $this->actingAs(User::find(1), 'api');
        $data = $this->getJson('/api/v1/reference/levels?center_id=1')->assertOk()->json('data');
        $this->assertCount(3, $data);
        $this->assertSame(['L1', 'L2', 'L3'], array_column($data, 'code'));
    }
}
