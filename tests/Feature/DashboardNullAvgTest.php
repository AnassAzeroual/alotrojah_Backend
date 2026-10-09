<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** §2.14a: pupils without session totals get NULL averages (never 0). */
class DashboardNullAvgTest extends TestCase
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

    public function test_scoreless_pupil_has_null_season_avg(): void
    {
        $this->actingAs(User::find(1), 'api');
        $id = $this->postJson('/api/v1/students', [
            'full_name' => 'No Scores Yet', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 1, 'group_id' => 1,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
        ])->assertCreated()->json('data.id');
        // A term plan puts the pupil in the dashboard view without any scores.
        $this->putJson('/api/v1/term-plans', [
            'student_id' => $id, 'term_id' => 1, 'plan_mode' => 'thumn',
        ])->assertCreated();
        $this->getJson("/api/v1/dashboard/season?student_id={$id}&season_id=1")
            ->assertOk()->assertJsonPath('data.season_avg_score', null);
    }
}
