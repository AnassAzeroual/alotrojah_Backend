<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** `GET /students?unassigned=1` — pupils with no group. Transaction-wrapped. */
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

    public function test_unassigned_returns_only_groupless_students(): void
    {
        $free = Student::create([
            'full_name' => 'Filter Free Pupil', 'center_id' => 1,
            'memorization_mode' => 'thumn',
        ]);
        $groupedId = DB::table('students')->whereNotNull('group_id')->value('id');
        $this->assertNotNull($groupedId);

        $ids = $this->actingAs(User::find(1), 'api')
            ->getJson('/api/v1/students?unassigned=1&q=Filter Free Pupil')
            ->assertOk()->json('data.data');
        $ids = collect($ids)->pluck('id')->all();

        $this->assertContains($free->id, $ids);
        $this->assertNotContains($groupedId, $ids);
    }
}
