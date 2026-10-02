<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * S13: delegation lifecycle + scoring edge cases. Transaction-wrapped.
 * NOTE: actingAs() is used instead of JWT tokens — the auth guard caches its
 * user per app instance, so two different tokens in ONE test would silently
 * authenticate as the first user. actingAs sets a fresh user per call.
 */
class DelegationScoringTest extends TestCase
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

    protected function tok(int $id): array
    {
        $this->actingAs(User::find($id), 'api');

        return ['Accept' => 'application/json'];
    }

    public function test_expired_token_redeem_fails(): void
    {
        $token = str_repeat('a', 64);
        DB::table('delegation_tokens')->insert([
            'group_id' => 1, 'granter_teacher_id' => 3, 'token' => $token,
            'duration_minutes' => 15, 'expires_at' => now()->subHour(), 'created_at' => now(),
        ]);
        $this->postJson('/api/v1/delegations/redeem', ['token' => $token], $this->tok(7))
            ->assertStatus(410);
    }

    public function test_revoked_token_stops_entry(): void
    {
        $gen = $this->postJson('/api/v1/groups/1/delegations', ['minutes' => 15], $this->tok(3));
        $gen->assertCreated();
        $id = $gen->json('data.delegation.id');
        $this->deleteJson("/api/v1/delegations/$id", [], $this->tok(3))->assertOk();

        $this->postJson('/api/v1/delegations/redeem', ['token' => $gen->json('data.token')], $this->tok(7))
            ->assertStatus(410);
    }

    public function test_token_binds_first_teacher_only(): void
    {
        $gen = $this->postJson('/api/v1/groups/1/delegations', ['minutes' => 15], $this->tok(3));
        $token = $gen->json('data.token');
        $this->postJson('/api/v1/delegations/redeem', ['token' => $token], $this->tok(7))->assertOk();
        // teacher 4 (same center, different teacher) cannot reuse teacher 7's binding
        $this->postJson('/api/v1/delegations/redeem', ['token' => $token], $this->tok(4))
            ->assertForbidden();
    }

    public function test_invalid_duration_rejected(): void
    {
        $this->postJson('/api/v1/groups/1/delegations', ['minutes' => 45], $this->tok(3))
            ->assertStatus(422);
    }

    public function test_student_cannot_redeem_delegation_token(): void
    {
        $gen = $this->postJson('/api/v1/groups/1/delegations', ['minutes' => 15], $this->tok(3));
        $token = $gen->json('data.token');

        $u = User::create([
            'full_name' => 'Student Redeem', 'email' => 'redeem@example.org',
            'password_hash' => Hash::make('password123'), 'role' => 'student', 'center_id' => 2,
        ]);
        $this->actingAs($u, 'api')
            ->postJson('/api/v1/delegations/redeem', ['token' => $token])
            ->assertForbidden();
    }

    public function test_module_deactivation_excludes_it_everywhere(): void
    {
        $this->putJson('/api/v1/scoring-modules', [
            'modules' => [['code' => 'sarraj', 'is_active' => false]],
        ], $this->tok(1))->assertOk();

        $r = $this->getJson('/api/v1/dashboard/season?student_id=1&season_id=1', $this->tok(1));
        $this->assertNull($r->json('data.season_avg_sarraj'));
        $check = $this->getJson('/api/v1/scoring-check', $this->tok(1));
        $check->assertJsonPath('data.valid', true); // weekly 20 untouched
    }

    public function test_invalid_rebalance_rolls_back(): void
    {
        $this->putJson('/api/v1/scoring-modules', [
            'modules' => [['code' => 'hifz', 'max_points' => 5]],
        ], $this->tok(1))->assertStatus(422);

        $this->assertEquals(14.0, (float) DB::table('scoring_modules')->where('code', 'hifz')->value('max_points'));
    }

    public function test_final_is_null_without_inputs(): void
    {
        $s = $this->postJson('/api/v1/students', [
            'full_name' => 'Fresh', 'memorization_mode' => 'thumn', 'center_id' => 1,
        ], $this->tok(1))->assertCreated();

        $r = $this->getJson("/api/v1/dashboard/final?student_id={$s->json('data.id')}&season_id=1", $this->tok(1));
        $r->assertOk()->assertJsonPath('data.final', null);
    }

    public function test_supervisor_can_enter_review_cycle(): void
    {
        $r = $this->postJson('/api/v1/murajaa-reviews', [
            'student_id' => 1, 'term_id' => 1, 'week_from' => 5, 'week_to' => 6, 'score' => 15,
        ], $this->tok(2));
        $r->assertCreated()->assertJsonPath('data.weeks_covered', 2);
    }
}
