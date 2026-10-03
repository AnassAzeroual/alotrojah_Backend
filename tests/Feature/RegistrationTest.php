<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\Group;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Self-registration waiting room. Transaction-wrapped (seed untouched).
 * ThrottleRequests disabled: register is throttled 6/min like login but this
 * class legitimately posts more than 6 times.
 */
class RegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'طالب تجريبي',
            'email' => 'new-student@example.org',
            'password' => 'password123',
            'role' => 'student',
            'phone' => '0612345678',
            'birth_date' => '2015-05-10',
            'gender' => 'male',
        ], $overrides);
    }

    public function test_student_registration_lands_in_waiting_room(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('registration_requests', [
            'email' => 'new-student@example.org',
            'role' => 'student',
            'gender' => 'male',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'new-student@example.org']);
    }

    public function test_teacher_registration_requires_teacher_type(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'role' => 'teacher', 'teacher_type' => null, 'birth_date' => null, 'gender' => null,
        ]))->assertStatus(422);

        $this->postJson('/api/v1/auth/register', $this->payload([
            'role' => 'teacher', 'teacher_type' => 'hifz', 'birth_date' => null, 'gender' => null,
        ]))->assertCreated();

        $this->assertDatabaseHas('registration_requests', [
            'email' => 'new-student@example.org', 'role' => 'teacher', 'teacher_type' => 'hifz',
        ]);
    }

    public function test_admin_role_cannot_self_register(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'role' => 'admin', 'birth_date' => null, 'gender' => null,
        ]))->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('registration_requests', ['email' => 'new-student@example.org']);
    }

    public function test_taken_email_is_rejected_with_code(): void
    {
        User::create([
            'full_name' => 'Existing', 'email' => 'new-student@example.org',
            'role' => 'teacher', 'center_id' => 1, 'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'email_taken');
    }

    public function test_duplicate_waiting_room_email_is_rejected_with_code(): void
    {
        RegistrationRequest::create([
            'full_name' => 'First Try', 'email' => 'new-student@example.org',
            'password_hash' => 'x', 'role' => 'teacher', 'phone' => '0612345678',
        ]);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'in_waiting_room');

        $this->assertSame(1, RegistrationRequest::where('email', 'new-student@example.org')->count());
    }

    public function test_only_admin_lists_requests(): void
    {
        RegistrationRequest::create([
            'full_name' => 'Waiting', 'email' => 'waiting@example.org',
            'password_hash' => 'x', 'role' => 'teacher', 'phone' => '0612345678',
        ]);

        $this->actingAs(User::find(2), 'api')->getJson('/api/v1/registration-requests')->assertForbidden();
        $this->actingAs(User::find(3), 'api')->getJson('/api/v1/registration-requests')->assertForbidden();

        $this->actingAs(User::find(1), 'api')->getJson('/api/v1/registration-requests')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.email', 'waiting@example.org');
    }

    public function test_accept_student_creates_user_and_student_row(): void
    {
        $request = RegistrationRequest::create([
            'full_name' => 'طالب تجريبي', 'email' => 'accepted@example.org',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('password123'),
            'role' => 'student', 'phone' => '0612345678',
            'birth_date' => '2000-01-01', 'gender' => 'female',
        ]);

        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/registration-requests/{$request->id}/accept", ['center_id' => 2])
            ->assertOk()
            ->assertJsonPath('data.email', 'accepted@example.org')
            ->assertJsonPath('data.center_id', 2)
            ->assertJsonPath('data.is_active', true);

        $user = User::where('email', 'accepted@example.org')->first();
        $this->assertNotNull($user);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password123', $user->password_hash));

        $student = Student::where('user_id', $user->id)->first();
        $this->assertNotNull($student);
        $this->assertSame('adult', $student->student_type); // born 2000 → 26 years old
        $this->assertSame(2, (int) $student->center_id);
        $this->assertSame('female', $student->gender);

        $this->assertDatabaseMissing('registration_requests', ['id' => $request->id]);
    }

    public function test_accept_child_student_sets_child_type(): void
    {
        $request = RegistrationRequest::create([
            'full_name' => 'Child', 'email' => 'child@example.org',
            'password_hash' => 'x', 'role' => 'student', 'phone' => '0612345678',
            'birth_date' => '2015-05-10', 'gender' => 'male',
        ]);

        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/registration-requests/{$request->id}/accept", ['center_id' => 1])
            ->assertOk();

        $userId = User::where('email', 'child@example.org')->value('id');
        $student = Student::where('user_id', $userId)->first();
        $this->assertNotNull($student);
        $this->assertSame('child', $student->student_type);
    }

    public function test_accept_teacher_keeps_teacher_type_without_student_row(): void
    {
        $request = RegistrationRequest::create([
            'full_name' => 'Teacher X', 'email' => 'teacher@example.org',
            'password_hash' => 'x', 'role' => 'teacher', 'teacher_type' => 'murajaa',
            'phone' => '0612345678',
        ]);

        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/registration-requests/{$request->id}/accept", ['center_id' => 1])
            ->assertOk()
            ->assertJsonPath('data.role', 'teacher')
            ->assertJsonPath('data.teacher_type', 'murajaa');

        $this->assertSame(0, Student::where('full_name', 'Teacher X')->count());
    }

    public function test_accept_requires_existing_center(): void
    {
        $request = RegistrationRequest::create([
            'full_name' => 'Ghost', 'email' => 'ghost@example.org',
            'password_hash' => 'x', 'role' => 'teacher', 'phone' => '0612345678',
        ]);

        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/registration-requests/{$request->id}/accept", ['center_id' => 999])
            ->assertStatus(422);

        $this->assertDatabaseHas('registration_requests', ['id' => $request->id]);
    }

    public function test_cancel_deletes_and_allows_fresh_registration(): void
    {
        $request = RegistrationRequest::create([
            'full_name' => 'Canceled', 'email' => 'new-student@example.org',
            'password_hash' => 'x', 'role' => 'teacher', 'phone' => '0612345678',
        ]);

        $this->actingAs(User::find(2), 'api')
            ->deleteJson("/api/v1/registration-requests/{$request->id}")->assertForbidden();

        $this->actingAs(User::find(1), 'api')
            ->deleteJson("/api/v1/registration-requests/{$request->id}")->assertOk();

        $this->assertDatabaseMissing('registration_requests', ['id' => $request->id]);

        // same email can start over after a cancel
        $this->postJson('/api/v1/auth/register', $this->payload([
            'role' => 'teacher', 'teacher_type' => 'hifz',
        ]))->assertCreated();
    }

    public function test_accept_teacher_with_group_assigns_group_teacher(): void
    {
        $group = Group::firstOrFail();

        $request = RegistrationRequest::create([
            'full_name' => 'Teacher G', 'email' => 'teacher-g@example.org',
            'password_hash' => 'x', 'role' => 'teacher', 'phone' => '0612345678',
        ]);

        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/registration-requests/{$request->id}/accept", [
                'center_id' => (int) $group->center_id,
                'group_id' => (int) $group->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'teacher')
            ->assertJsonPath('data.center_id', (int) $group->center_id);

        $user = User::where('email', 'teacher-g@example.org')->first();
        $this->assertNotNull($user);
        $this->assertSame((int) $user->id, (int) $group->fresh()->teacher_id);
    }

    public function test_accept_student_with_group_assigns_student_group(): void
    {
        $group = Group::firstOrFail();

        $request = RegistrationRequest::create([
            'full_name' => 'Student G', 'email' => 'student-g@example.org',
            'password_hash' => 'x', 'role' => 'student', 'phone' => '0612345678',
            'birth_date' => '2015-05-10', 'gender' => 'female',
        ]);

        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/registration-requests/{$request->id}/accept", [
                'center_id' => (int) $group->center_id,
                'group_id' => (int) $group->id,
            ])
            ->assertOk();

        $student = Student::where('full_name', 'Student G')->first();
        $this->assertNotNull($student);
        $this->assertSame((int) $group->id, (int) $student->group_id);
        $this->assertSame((int) $group->center_id, (int) $student->center_id);
    }

    public function test_accept_rejects_group_from_another_center(): void
    {
        $group = Group::firstOrFail();
        $otherCenterId = (int) Center::where('id', '<>', $group->center_id)->firstOrFail()->id;

        $request = RegistrationRequest::create([
            'full_name' => 'Wrong Center', 'email' => 'wrong-center@example.org',
            'password_hash' => 'x', 'role' => 'teacher', 'phone' => '0612345678',
        ]);

        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/registration-requests/{$request->id}/accept", [
                'center_id' => $otherCenterId,
                'group_id' => (int) $group->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Group belongs to another center.');

        $this->assertDatabaseHas('registration_requests', ['id' => $request->id]);
        $this->assertDatabaseMissing('users', ['email' => 'wrong-center@example.org']);
    }

    public function test_accept_rejects_group_assignment_for_non_teaching_roles(): void
    {
        $group = Group::firstOrFail();

        foreach (['supervisor', 'board'] as $role) {
            $request = RegistrationRequest::create([
                'full_name' => "Role {$role}", 'email' => "{$role}@example.org",
                'password_hash' => 'x', 'role' => $role, 'phone' => '0612345678',
            ]);

            $this->actingAs(User::find(1), 'api')
                ->postJson("/api/v1/registration-requests/{$request->id}/accept", [
                    'center_id' => (int) $group->center_id,
                    'group_id' => (int) $group->id,
                ])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Group assignment only applies to teachers and students.');
        }
    }

    public function test_accept_teacher_without_group_leaves_groups_untouched(): void
    {
        $group = Group::firstOrFail();
        $teacherBefore = $group->teacher_id;

        $request = RegistrationRequest::create([
            'full_name' => 'Teacher NoGroup', 'email' => 'teacher-nogroup@example.org',
            'password_hash' => 'x', 'role' => 'teacher', 'phone' => '0612345678',
        ]);

        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/registration-requests/{$request->id}/accept", [
                'center_id' => (int) $group->center_id,
            ])
            ->assertOk();

        $this->assertSame($teacherBefore, $group->fresh()->teacher_id);
    }
}
