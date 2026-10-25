<?php

namespace Tests\Feature;

use App\Models\AcademicSeason;
use App\Models\Level;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeasonLevelFixtures;
use Tests\TestCase;

/** Levels CRUD is season-scoped: admin-only writes, in-place edits, guarded delete. */
class LevelManageTest extends TestCase
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

    /** A fresh season with the admin-created set (no static seed exists). */
    private function seasonWithLevels(?int $centerId = null): AcademicSeason
    {
        $season = AcademicSeason::create([
            'name' => 'Test Season '.uniqid(),
            'start_date' => '2026-09-01',
            'total_weeks' => 0,
            'total_sessions' => 0,
            'is_current' => false,
            'center_id' => $centerId,
        ]);
        $this->seedSeasonLevels($season);

        return $season;
    }

    public function test_admin_edits_a_season_level_in_place(): void
    {
        $this->actingAs(User::find(1), 'api');
        $season = $this->seasonWithLevels();
        $l1 = Level::where('season_id', $season->id)->where('code', 'L1')->firstOrFail();

        $this->putJson("/api/v1/levels/{$l1->id}", ['sessions_per_week' => 5])
            ->assertOk()
            ->assertJsonPath('data.season_id', $season->id)
            ->assertJsonPath('data.sessions_per_week', 5);

        $data = $this->getJson("/api/v1/levels?season_id={$season->id}")->assertOk()->json('data');
        $this->assertSame(5, collect($data)->firstWhere('code', 'L1')['sessions_per_week']);
    }

    public function test_editing_one_season_does_not_touch_another(): void
    {
        $this->actingAs(User::find(1), 'api');
        $a = $this->seasonWithLevels();
        $b = $this->seasonWithLevels();
        $a1 = Level::where('season_id', $a->id)->where('code', 'L1')->firstOrFail();
        $b1 = Level::where('season_id', $b->id)->where('code', 'L1')->firstOrFail();

        $this->putJson("/api/v1/levels/{$a1->id}", ['sessions_per_week' => 5])->assertOk();

        $this->assertSame(5, (int) $a1->fresh()->sessions_per_week);
        $this->assertSame(3, (int) $b1->fresh()->sessions_per_week);
    }

    public function test_delete_refused_when_used_and_allowed_when_free(): void
    {
        $this->actingAs(User::find(1), 'api');
        $used = Level::whereHas('students')->firstOrFail();
        $this->deleteJson("/api/v1/levels/{$used->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'LEVEL_IN_USE');

        $season = $this->seasonWithLevels();
        $free = Level::where('season_id', $season->id)->where('code', 'L3')->firstOrFail();
        $this->deleteJson("/api/v1/levels/{$free->id}")->assertOk();
        $this->assertNull(Level::find($free->id));
    }

    public function test_index_scopes_by_season_and_center(): void
    {
        $this->actingAs(User::find(1), 'api');

        // No scope = never a global set.
        $this->assertSame([], $this->getJson('/api/v1/levels')->assertOk()->json('data'));

        // A center resolves to its latest own season.
        $season = $this->seasonWithLevels(1);
        $data = $this->getJson('/api/v1/levels?center_id=1')->assertOk()->json('data');
        $this->assertSame(['L1', 'L2', 'L3'], array_column($data, 'code'));
        $this->assertSame($season->id, $data[0]['season_id']);

        // A center with no own season falls back to the legacy shared season;
        // with the shared lane parked on center 2, center 3 has nothing.
        DB::table('academic_seasons')->where('id', 1)->update(['center_id' => 2]);
        $this->assertSame([], $this->getJson('/api/v1/levels?center_id=3')->assertOk()->json('data'));
        $this->assertCount(3, $this->getJson('/api/v1/levels?center_id=2')->assertOk()->json('data'));
    }

    public function test_non_admin_cannot_write_levels(): void
    {
        $season = $this->seasonWithLevels();
        $level = Level::where('season_id', $season->id)->where('code', 'L1')->firstOrFail();

        $this->actingAs(User::find(2), 'api');
        // Store exists since dynamic codes (2026-10-14) but stays admin-only.
        $this->postJson('/api/v1/levels', ['name_ar' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/levels/{$level->id}", ['sessions_per_week' => 5])->assertForbidden();
        $this->actingAs(User::find(3), 'api');
        $this->getJson('/api/v1/levels')->assertOk();
    }
}
