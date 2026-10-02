<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * S12: performance + math guards. All writes roll back (dev seed untouched).
 */
class PerformanceTest extends TestCase
{
    protected int $queryCount = 0;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        DB::listen(function () { $this->queryCount++; });
        $this->token = JWTAuth::fromUser(User::find(1));
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    protected function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    protected function resetQueries(): void
    {
        $this->queryCount = 0;
    }

    public function test_students_index_is_paginated_and_query_bounded(): void
    {
        $this->resetQueries();
        $r = $this->getJson('/api/v1/students', $this->auth());
        $r->assertOk()->assertJsonPath('data.meta.total', 7);
        $this->assertLessThanOrEqual(12, $this->queryCount, "N+1? {$this->queryCount} queries");
    }

    public function test_followup_is_query_bounded(): void
    {
        $this->resetQueries();
        $r = $this->getJson('/api/v1/students/1/weeks/1/followup', $this->auth());
        $r->assertOk()->assertJsonCount(3, 'data.logs');
        $this->assertLessThanOrEqual(15, $this->queryCount, "N+1? {$this->queryCount} queries");
    }

    public function test_scores_bulk_is_idempotent(): void
    {
        $payload = ['session_id' => 9, 'records' => [
            ['student_id' => 1, 'module_code' => 'hifz', 'score' => 12],
            ['student_id' => 1, 'module_code' => 'mowathaba', 'score' => 3],
        ]];
        $r1 = $this->postJson('/api/v1/scores/bulk', $payload, $this->auth());
        $r1->assertCreated();
        $this->assertEquals(15.0, $r1->json('data.weekly_totals.1'));
        $r2 = $this->postJson('/api/v1/scores/bulk', $payload, $this->auth());
        $r2->assertCreated();
        $this->assertEquals(15.0, $r2->json('data.weekly_totals.1'));
        $this->assertEquals(2, DB::table('session_scores')->where('session_id', 9)->count());
    }

    public function test_final_matches_hand_math(): void
    {
        // Transparent math: final must equal the formula applied to the
        // response's own displayed components — no hardcoded seed values,
        // so dev-data edits (e.g. avg_weekly 18.25 → 18.3) can't stale it.
        $r = $this->getJson('/api/v1/dashboard/final?student_id=1&season_id=1', $this->auth());
        $r->assertOk()->assertJsonPath('data.divisor', 4);
        $inputs = $r->json('data.inputs');
        $quizSum = collect($r->json('data.quizzes'))->sum(fn ($q) => (float) $q['avg_score']);
        $expected = round(
            ((float) $inputs['avg_murajaa'] + (float) $inputs['avg_weekly'] + $quizSum)
            / (int) $r->json('data.divisor'),
            2,
        );
        $this->assertSame($expected, $r->json('data.final'));
    }

    public function test_scoring_guard_stays_twenty(): void
    {
        $r = $this->getJson('/api/v1/scoring-check', $this->auth());
        $r->assertOk()->assertJsonPath('data.valid', true);
        $this->assertEquals(20.0, $r->json('data.total'));
    }
}
