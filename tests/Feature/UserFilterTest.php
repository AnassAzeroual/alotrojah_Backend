<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** `GET /users?unassigned=1` — teachers with no ACTIVE group. Transaction-wrapped. */
class UserFilterTest extends TestCase
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

    private function makeTeacher(string $email): User
    {
        return User::create([
            'full_name' => "Filter {$email}", 'email' => $email,
            'password_hash' => Hash::make('password123'), 'role' => 'teacher',
            'center_id' => 1, 'teacher_type' => 'hifz', 'is_active' => true,
        ]);
    }

    public function test_unassigned_returns_only_group_free_teachers(): void
    {
        $free = $this->makeTeacher('free9@example.org');
        $busy = $this->makeTeacher('busy9@example.org');
        Group::create([
            'name' => 'Filter Group', 'center_id' => 1, 'level_id' => 1,
            'teacher_id' => $busy->id, 'is_active' => true,
        ]);

        $ids = $this->actingAs(User::find(1), 'api')
            ->getJson('/api/v1/users?role=teacher&unassigned=1&q=free9')
            ->assertOk()->json('data.data');
        $ids = collect($ids)->pluck('id')->all();

        $this->assertContains($free->id, $ids);
        $this->assertNotContains($busy->id, $ids);
    }

    public function test_teacher_with_only_inactive_group_counts_as_unassigned(): void
    {
        $t = $this->makeTeacher('oldfree9@example.org');
        Group::create([
            'name' => 'Filter Old Group', 'center_id' => 1, 'level_id' => 1,
            'teacher_id' => $t->id, 'is_active' => false,
        ]);

        $ids = $this->actingAs(User::find(1), 'api')
            ->getJson('/api/v1/users?role=teacher&unassigned=1&q=oldfree9')
            ->assertOk()->json('data.data');
        $this->assertContains($t->id, collect($ids)->pluck('id')->all());
    }

    public function test_unassigned_off_returns_everyone(): void
    {
        $busy = $this->makeTeacher('busy10@example.org');
        Group::create([
            'name' => 'Filter Group 2', 'center_id' => 1, 'level_id' => 1,
            'teacher_id' => $busy->id, 'is_active' => true,
        ]);

        $ids = $this->actingAs(User::find(1), 'api')
            ->getJson('/api/v1/users?role=teacher&q=busy10')
            ->assertOk()->json('data.data');
        $this->assertContains($busy->id, collect($ids)->pluck('id')->all());
    }
}
