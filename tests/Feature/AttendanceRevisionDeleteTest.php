<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\RevisionLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** DELETE attendance + revision-logs (Item 5 surfaces). Transaction-wrapped. */
class AttendanceRevisionDeleteTest extends TestCase
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

    public function test_teacher_can_delete_attendance_row(): void
    {
        $row = Attendance::firstOrFail();
        $this->actingAs(User::find(3), 'api')
            ->deleteJson("/api/v1/attendance/{$row->id}")
            ->assertOk();
        $this->assertNull(Attendance::find($row->id));
    }

    public function test_supervisor_can_delete_revision_log(): void
    {
        $log = RevisionLog::create([
            'student_id' => 1, 'season_id' => 1, 'term_id' => 1,
            'week_id' => 1, 'session_id' => 1, 'log_date' => '2026-10-01',
            'murajaa_score' => 15, 'entered_by' => 19,
        ]);
        $this->actingAs(User::find(2), 'api')
            ->deleteJson("/api/v1/revision-logs/{$log->id}")
            ->assertOk();
        $this->assertNull(RevisionLog::find($log->id));
    }

    public function test_teacher_cannot_delete_revision_log(): void
    {
        $log = RevisionLog::create([
            'student_id' => 1, 'season_id' => 1, 'term_id' => 1,
            'week_id' => 1, 'session_id' => 1, 'log_date' => '2026-10-01',
            'murajaa_score' => 15, 'entered_by' => 19,
        ]);
        $this->actingAs(User::find(3), 'api')
            ->deleteJson("/api/v1/revision-logs/{$log->id}")
            ->assertForbidden();
    }
}
