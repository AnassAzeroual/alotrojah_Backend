<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** §2.3: student-role users see only their own results, never the center's. */
class ResultPrivacyTest extends TestCase
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

    public function test_student_sees_only_own_results(): void
    {
        $this->actingAs(User::find(1), 'api');
        $mine = $this->postJson('/api/v1/students', [
            'full_name' => 'Mine', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 1, 'group_id' => 1,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
        ])->assertCreated()->json('data.id');
        $other = $this->postJson('/api/v1/students', [
            'full_name' => 'Other', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 1, 'group_id' => 1,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
        ])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/term-results', ['student_id' => $mine, 'term_id' => 1])->assertCreated();
        $this->putJson('/api/v1/term-results', ['student_id' => $other, 'term_id' => 1])->assertCreated();
        $this->putJson('/api/v1/season-results', ['student_id' => $mine, 'season_id' => 1])->assertCreated();
        $this->putJson('/api/v1/season-results', ['student_id' => $other, 'season_id' => 1])->assertCreated();

        $s = User::create([
            'full_name' => 'Pupil Account', 'email' => 'pupil.privacy@example.org',
            'password_hash' => Hash::make('password123'),
            'role' => 'student', 'center_id' => 1, 'is_active' => true,
        ]);
        DB::table('students')->where('id', $mine)->update(['user_id' => $s->id]);

        $this->actingAs($s, 'api');
        $this->getJson('/api/v1/term-results')->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.student_id', $mine);
        $this->getJson("/api/v1/term-results?student_id={$other}")->assertOk()
            ->assertJsonCount(0, 'data.data');
        $this->getJson('/api/v1/season-results')->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.student_id', $mine);
        $this->getJson("/api/v1/season-results?student_id={$other}")->assertOk()
            ->assertJsonCount(0, 'data.data');
    }
}
