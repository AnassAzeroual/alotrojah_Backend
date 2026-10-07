<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Level;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 1NF: group weekdays live in group_weekdays, not a CSV string. The API
 * shape is unchanged (joined string on read, string array on write).
 */
class GroupWeekdaysTest extends TestCase
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

    private function teacherId(): int
    {
        return User::create([
            'full_name' => 'Weekday Teacher', 'email' => 'weekday-teacher@example.org',
            'password_hash' => 'x', 'role' => 'teacher',
            'center_id' => 1, 'teacher_type' => 'hifz', 'is_active' => true,
        ])->id;
    }

    public function test_create_stores_days_as_rows_and_reads_joined_string(): void
    {
        $this->actingAs(User::find(1), 'api');
        $level = Level::where('code', 'L1')->firstOrFail()->id;
        $id = $this->postJson('/api/v1/groups', [
            'name' => 'Weekday Group', 'center_id' => 1, 'level_id' => $level,
            'teacher_id' => $this->teacherId(),
            'schedule_days' => ['Wed', 'Mon'],
        ])->assertCreated()->json('data.id');

        $stored = DB::table('group_weekdays')->where('group_id', $id)->pluck('weekday')->all();
        sort($stored);
        $this->assertSame(['Mon', 'Wed'], $stored);
        $this->getJson("/api/v1/groups/{$id}")->assertOk()->assertJsonPath('data.schedule_days', 'Mon,Wed');
    }

    public function test_update_replaces_days(): void
    {
        $this->actingAs(User::find(1), 'api');
        $level = Level::where('code', 'L1')->firstOrFail()->id;
        $id = $this->postJson('/api/v1/groups', [
            'name' => 'Swap Group', 'center_id' => 1, 'level_id' => $level,
            'teacher_id' => $this->teacherId(),
            'schedule_days' => ['Mon'],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/groups/{$id}", ['schedule_days' => ['Fri']])->assertOk();
        $this->assertSame(['Fri'], DB::table('group_weekdays')->where('group_id', $id)->pluck('weekday')->all());
    }

    public function test_invalid_weekday_is_rejected(): void
    {
        $this->actingAs(User::find(1), 'api');
        $level = Level::where('code', 'L1')->firstOrFail()->id;
        $this->postJson('/api/v1/groups', [
            'name' => 'Bad Days', 'center_id' => 1, 'level_id' => $level,
            'teacher_id' => $this->teacherId(),
            'schedule_days' => ['Funday'],
        ])->assertStatus(422);
    }

    public function test_create_without_days_stores_none(): void
    {
        $this->actingAs(User::find(1), 'api');
        $level = Level::where('code', 'L1')->firstOrFail()->id;
        $id = $this->postJson('/api/v1/groups', [
            'name' => 'Dateless Group', 'center_id' => 1, 'level_id' => $level,
            'teacher_id' => $this->teacherId(),
        ])->assertCreated()->json('data.id');

        $this->assertSame('', Group::find($id)->schedule_days);
    }

    public function test_duplicate_weekday_row_is_refused_by_unique(): void
    {
        $this->actingAs(User::find(1), 'api');
        $level = Level::where('code', 'L1')->firstOrFail()->id;
        $id = $this->postJson('/api/v1/groups', [
            'name' => 'Dup Group', 'center_id' => 1, 'level_id' => $level,
            'teacher_id' => $this->teacherId(),
            'schedule_days' => ['Mon'],
        ])->assertCreated()->json('data.id');

        $this->expectException(QueryException::class);
        DB::table('group_weekdays')->insert(['group_id' => $id, 'weekday' => 'Mon']);
    }
}
