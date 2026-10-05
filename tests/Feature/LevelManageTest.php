<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Levels CRUD: admin-only writes, scoped creates, clone-on-scoped-edit, guarded delete. */
class LevelManageTest extends TestCase
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

    public function test_admin_edits_levels_only_through_scoped_clones(): void
    {
        $this->actingAs(User::find(1), 'api');
        // Same code resolves per set: override wins, defaults fill gaps.
        $this->putJson('/api/v1/levels/1', ['sessions_per_week' => 5, 'center_id' => 2])
            ->assertOk()->assertJsonPath('data.center_id', 2);
        $data = $this->getJson('/api/v1/reference/levels?center_id=2')->assertOk()->json('data');
        $this->assertSame(5, collect($data)->firstWhere('code', 'L1')['sessions_per_week']);
        $data = $this->getJson('/api/v1/reference/levels')->assertOk()->json('data');
        $this->assertSame(3, collect($data)->firstWhere('code', 'L1')['sessions_per_week']);
    }

    public function test_scoped_edit_clones_instead_of_mutating_defaults(): void
    {
        $this->actingAs(User::find(1), 'api');
        $l1 = Level::where('code', 'L1')->whereNull('center_id')->firstOrFail();
        $this->putJson("/api/v1/levels/{$l1->id}", [
            'sessions_per_week' => 5, 'center_id' => 1,
        ])->assertOk()->assertJsonPath('data.center_id', 1)
            ->assertJsonPath('data.sessions_per_week', 5);
        // Default row untouched.
        $this->assertSame(3, (int) $l1->fresh()->sessions_per_week);
        // Whole C1 set materialized.
        $this->assertSame(3, Level::where('center_id', 1)->count());
    }

    public function test_delete_refused_when_used_and_allowed_when_free(): void
    {
        $this->actingAs(User::find(1), 'api');
        $used = Level::whereHas('groups')->firstOrFail();
        $this->deleteJson("/api/v1/levels/{$used->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'LEVEL_IN_USE');

        $free = Level::where('code', 'L3')->whereNull('center_id')->firstOrFail();
        // An override row with no references deletes freely.
        $clone = $free->replicate();
        $clone->center_id = 2;
        $clone->save();
        $this->deleteJson("/api/v1/levels/{$clone->id}")->assertOk();
        $this->assertNull(Level::find($clone->id));
    }

    public function test_non_admin_cannot_write_levels(): void
    {
        $this->actingAs(User::find(2), 'api');
        // No store route at all (codes are a fixed ENUM); update is admin-only.
        $this->postJson('/api/v1/levels', ['code' => 'L1'])->assertStatus(405);
        $this->putJson('/api/v1/levels/1', ['sessions_per_week' => 5])->assertForbidden();
        $this->actingAs(User::find(3), 'api');
        $this->getJson('/api/v1/levels')->assertOk();
    }
}
