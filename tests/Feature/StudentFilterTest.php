<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** `GET /students?unassigned=1` — NOT NULL world: every pupil is placed, so the filter is always empty (kept for UI compat). */
class StudentFilterTest extends TestCase
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

    public function test_unassigned_returns_empty_when_every_pupil_is_placed(): void
    {
        $teacher = User::create([
            'full_name' => 'Filter Teacher', 'email' => 'filter-teacher@example.org',
            'password_hash' => 'x', 'role' => 'teacher',
            'center_id' => 1, 'teacher_type' => 'hifz', 'is_active' => true,
        ]);
        $group = Group::create([
            'name' => 'Filter Group',
            'center_id' => 1, 'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
            'teacher_id' => $teacher->id, 'is_active' => true,
        ]);
        Student::create([
            'full_name' => 'Filter Placed Pupil', 'center_id' => 1,
            'group_id' => $group->id, 'level_id' => $group->level_id,
            'memorization_mode' => 'thumn',
        ]);

        $ids = $this->actingAs(User::find(1), 'api')
            ->getJson('/api/v1/students?unassigned=1')
            ->assertOk()->json('data.data');
        $this->assertSame([], $ids);
    }
}
