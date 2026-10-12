<?php

namespace Tests\Feature;

use App\Models\MemorizationLog;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §B memorization capture in daily entry: /scores/bulk now accepts an optional
 * memorizations array that upserts memorization_logs per (student, session).
 * Transaction-wrapped (seed untouched).
 */
class MemorizationCaptureTest extends TestCase
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

    /** Teacher 3 + pupil 1 + group 1 live in center 1; pupil 4 lives in center 2. */
    private function sessionForGroupOne(): Session
    {
        $this->assertNotNull(Session::where('group_id', 1)->first());
        return Session::where('group_id', 1)->firstOrFail();
    }

    private function payload(int $sessionId, array $memorizations = []): array
    {
        return [
            'session_id' => $sessionId,
            'records' => [
                ['student_id' => 1, 'module_code' => 'hifz', 'score' => 8],
            ],
            'memorizations' => $memorizations,
        ];
    }

    public function test_teacher_saves_memorization_amount_for_own_center_student(): void
    {
        $session = $this->sessionForGroupOne();
        $this->actingAs(User::find(3), 'api'); // hifz teacher, center 1

        $this->postJson('/api/v1/scores/bulk', $this->payload($session->id, [
            ['student_id' => 1, 'thumn_amount' => 1.25],
        ]))->assertCreated()->assertJsonPath('data.memorizations_saved', 1);

        $this->assertDatabaseHas('memorization_logs', [
            'student_id' => 1, 'session_id' => $session->id, 'thumn_amount' => 1.25,
            'season_id' => $session->season_id, 'term_id' => $session->term_id,
            'week_id' => $session->week_id,
        ]);
        $this->assertSame(
            1,
            MemorizationLog::where('student_id', 1)->where('session_id', $session->id)->count()
        );
    }

    public function test_resave_upserts_not_accumulates(): void
    {
        $session = $this->sessionForGroupOne();
        $this->actingAs(User::find(3), 'api');

        $this->postJson('/api/v1/scores/bulk', $this->payload($session->id, [
            ['student_id' => 1, 'thumn_amount' => 1.25],
        ]))->assertCreated();
        $this->postJson('/api/v1/scores/bulk', $this->payload($session->id, [
            ['student_id' => 1, 'thumn_amount' => 1.5],
        ]))->assertCreated()->assertJsonPath('data.memorizations_saved', 1);

        $this->assertSame(
            1,
            MemorizationLog::where('student_id', 1)->where('session_id', $session->id)->count()
        );
        $this->assertDatabaseHas('memorization_logs', [
            'student_id' => 1, 'session_id' => $session->id, 'thumn_amount' => 1.5,
        ]);
    }

    public function test_blank_amount_removes_the_log(): void
    {
        $session = $this->sessionForGroupOne();
        $this->actingAs(User::find(3), 'api');

        $this->postJson('/api/v1/scores/bulk', $this->payload($session->id, [
            ['student_id' => 1, 'thumn_amount' => 1.25],
        ]))->assertCreated();
        $this->postJson('/api/v1/scores/bulk', $this->payload($session->id, [
            ['student_id' => 1, 'thumn_amount' => null],
        ]))->assertCreated()->assertJsonPath('data.memorizations_saved', 0);

        $this->assertDatabaseMissing('memorization_logs', [
            'student_id' => 1, 'session_id' => $session->id,
        ]);
    }

    public function test_out_of_center_student_rejected(): void
    {
        $session = $this->sessionForGroupOne();
        $this->actingAs(User::find(3), 'api'); // teacher, center 1

        $this->postJson('/api/v1/scores/bulk', $this->payload($session->id, [
            ['student_id' => 1, 'thumn_amount' => 1.25],
            ['student_id' => 4, 'thumn_amount' => 1.0], // center 2
        ]))->assertUnprocessable();
    }
}