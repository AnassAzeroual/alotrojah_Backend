<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pupil↔account link (owner-directed 2026-10-15): every pupil rides a
 * login account — POST /students rejects NULL user_id, non-student users
 * and already-linked accounts; the account must share the pupil's center.
 * Grandfathered NULL rows link later through PUT.
 */
class StudentAccountTest extends TestCase
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

    private function account(string $email, string $role = 'student', int $center = 1, bool $active = true): User
    {
        return User::create([
            'full_name' => "Account {$email}", 'email' => $email,
            'password_hash' => 'x', 'role' => $role,
            'center_id' => $center, 'is_active' => $active,
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'full_name' => 'Linked Pupil', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 1, 'group_id' => 1,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
        ], $over);
    }

    public function test_store_rejects_missing_user_id(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->postJson('/api/v1/students', $this->payload())->assertUnprocessable();
    }

    public function test_store_rejects_non_student_account(): void
    {
        $this->actingAs(User::find(1), 'api');
        $t = $this->account('teacher-acct@example.org', 'teacher');
        $this->postJson('/api/v1/students', $this->payload(['user_id' => $t->id]))
            ->assertUnprocessable();
    }

    public function test_store_rejects_already_linked_account(): void
    {
        $this->actingAs(User::find(1), 'api');
        $u = $this->account('twice@example.org');
        $this->postJson('/api/v1/students', $this->payload(['user_id' => $u->id]))->assertCreated();
        $this->postJson('/api/v1/students', $this->payload(['full_name' => 'Second', 'user_id' => $u->id]))
            ->assertUnprocessable();
    }

    public function test_store_rejects_cross_center_account(): void
    {
        $this->actingAs(User::find(1), 'api');
        $u = $this->account('c2-acct@example.org', 'student', 2);
        $this->postJson('/api/v1/students', $this->payload(['user_id' => $u->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'CROSS_CENTER');
    }

    public function test_store_links_the_account(): void
    {
        $this->actingAs(User::find(1), 'api');
        $u = $this->account('linked-ok@example.org');
        $id = $this->postJson('/api/v1/students', $this->payload(['user_id' => $u->id]))
            ->assertCreated()->json('data.id');
        $this->assertSame($u->id, (int) Student::find($id)->user_id);
    }

    public function test_update_links_a_grandfathered_row_and_refuses_unlink(): void
    {
        $this->actingAs(User::find(1), 'api');
        $id = Student::create([
            'full_name' => 'Legacy', 'center_id' => 1, 'group_id' => 1,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
            'memorization_mode' => 'thumn',
        ])->id;
        $u = $this->account('legacy-link@example.org');
        $this->putJson("/api/v1/students/{$id}", ['user_id' => $u->id])
            ->assertOk()->assertJsonPath('data.user_id', $u->id);
        $this->putJson("/api/v1/students/{$id}", ['user_id' => null])->assertUnprocessable();
    }

    public function test_unlinked_accounts_lists_only_linkable_pupil_accounts(): void
    {
        $this->actingAs(User::find(1), 'api');
        $free = $this->account('free-acct@example.org');
        $taken = $this->account('taken-acct@example.org');
        $this->postJson('/api/v1/students', $this->payload(['user_id' => $taken->id]))->assertCreated();
        $teacher = $this->account('teacher-acct2@example.org', 'teacher');
        $off = $this->account('off-acct@example.org', 'student', 1, false);
        $c2 = $this->account('c2-free@example.org', 'student', 2);

        $ids = collect(
            $this->getJson('/api/v1/students/unlinked-accounts')->assertOk()->json('data')
        )->pluck('id')->all();
        $this->assertContains($free->id, $ids);
        $this->assertNotContains($taken->id, $ids);
        $this->assertNotContains($teacher->id, $ids);
        $this->assertNotContains($off->id, $ids);

        // Center narrowing: supervisor sees only their own center's free accounts.
        $supIds = collect(
            $this->actingAs(User::find(2), 'api')
                ->getJson('/api/v1/students/unlinked-accounts')->assertOk()->json('data')
        )->pluck('id')->all();
        $this->assertContains($free->id, $supIds);
        foreach (
            $this->actingAs(User::find(1), 'api')
                ->getJson('/api/v1/students/unlinked-accounts?center_id=2')->assertOk()->json('data') as $row
        ) {
            $this->assertSame(2, (int) $row['center_id']);
        }

        // Teachers may list linkable accounts of their own center (they create pupils).
        $teachIds = collect(
            $this->actingAs(User::find(3), 'api')
                ->getJson('/api/v1/students/unlinked-accounts')->assertOk()->json('data')
        )->pluck('id')->all();
        $this->assertContains($free->id, $teachIds);
    }
}
