<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SeasonLevelFixtures;
use Tests\TestCase;

/** `GET /users?unassigned=1` — no ACTIVE group to teach AND no pupil row
 * linked (free for either assignment). Transaction-wrapped. */
class UserFilterTest extends TestCase
{
    use SeasonLevelFixtures;

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
            'name' => 'Filter Group', 'center_id' => 1,
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
            'name' => 'Filter Old Group', 'center_id' => 1,
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
            'name' => 'Filter Group 2', 'center_id' => 1,
            'teacher_id' => $busy->id, 'is_active' => true,
        ]);

        $ids = $this->actingAs(User::find(1), 'api')
            ->getJson('/api/v1/users?role=teacher&q=busy10')
            ->assertOk()->json('data.data');
        $this->assertContains($busy->id, collect($ids)->pluck('id')->all());
    }

    public function test_unassigned_excludes_pupil_linked_accounts(): void
    {
        $u = User::create([
            'full_name' => 'Linked Pupil Acct', 'email' => 'linked-acct9@example.org',
            'password_hash' => Hash::make('password123'), 'role' => 'student',
            'center_id' => 1, 'is_active' => true,
        ]);
        // Sanity: a link-free account shows up unfiltered and under the flag.
        $this->actingAs(User::find(1), 'api')
            ->getJson('/api/v1/users?q=Linked%20Pupil%20Acct')
            ->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/v1/users?unassigned=1&q=Linked%20Pupil%20Acct')
            ->assertOk()->assertJsonPath('data.meta.total', 1);

        $this->postJson('/api/v1/students', [
            'full_name' => 'Linked Pupil', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 1, 'group_id' => 1,
            'level_id' => $this->currentLevelId(1), 'user_id' => $u->id,
        ])->assertCreated();

        // Linked: gone from the flag, with or without a role filter.
        $this->getJson('/api/v1/users?unassigned=1&q=Linked%20Pupil%20Acct')
            ->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/v1/users?role=student&unassigned=1&q=Linked%20Pupil%20Acct')
            ->assertOk()->assertJsonPath('data.meta.total', 0);
    }
}
