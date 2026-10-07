<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** §2.16: students get their own dashboard; nothing center-wide, no 403s. */
class StudentDashboardTest extends TestCase
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

    private function makeStudentUser(string $email): User
    {
        return User::create([
            'full_name' => 'Pupil ' . $email, 'email' => $email,
            'password_hash' => Hash::make('password123'),
            'role' => 'student', 'center_id' => 1, 'is_active' => true,
        ]);
    }

    public function test_student_without_linked_pupil_gets_null(): void
    {
        $this->actingAs($this->makeStudentUser('lonely@example.org'), 'api');
        $this->getJson('/api/v1/dashboard/me')->assertOk()->assertJsonPath('data', null);
    }

    public function test_student_with_linked_pupil_gets_own_data(): void
    {
        $this->actingAs(User::find(1), 'api');
        $id = $this->postJson('/api/v1/students', [
            'full_name' => 'Linked Pupil', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 1,
        ])->assertCreated()->json('data.id');
        $s = $this->makeStudentUser('linked@example.org');
        DB::table('students')->where('id', $id)->update(['user_id' => $s->id]);

        $this->actingAs($s, 'api');
        $this->getJson('/api/v1/dashboard/me')->assertOk()
            ->assertJsonPath('data.student.id', $id)
            ->assertJsonStructure(['data' => ['student', 'season', 'weekly', 'final']]);
    }

    public function test_non_student_cannot_use_me(): void
    {
        $this->actingAs(User::find(3), 'api');
        $this->getJson('/api/v1/dashboard/me')->assertForbidden();
    }
}
