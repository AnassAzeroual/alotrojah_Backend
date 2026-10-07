<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * teacher_type is meaningful only for teachers (M5): the model normalizes
 * every write path to null for non-teachers (and to 'both' for teachers
 * without a type), locked by chk_users_teacher_type / chk_regrequests_teacher_type.
 */
class TeacherTypeScopeTest extends TestCase
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

    public function test_non_teacher_type_is_nulled_on_create(): void
    {
        $this->actingAs(User::find(1), 'api');
        $id = $this->postJson('/api/v1/users', [
            'full_name' => 'Typed Supervisor', 'email' => 'typed.sup@example.org',
            'password' => 'password123', 'role' => 'supervisor', 'center_id' => 1,
            'teacher_type' => 'hifz',
        ])->assertCreated()->json('data.id');

        $this->assertNull(User::find($id)->teacher_type);
    }

    public function test_role_change_to_non_teacher_nulls_type(): void
    {
        $this->actingAs(User::find(1), 'api');
        $id = $this->postJson('/api/v1/users', [
            'full_name' => 'Switching', 'email' => 'switch@example.org',
            'password' => 'password123', 'role' => 'teacher', 'center_id' => 1,
            'teacher_type' => 'murajaa',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/users/{$id}", ['role' => 'supervisor'])->assertOk();
        $this->assertNull(User::find($id)->fresh()->teacher_type);
    }

    public function test_teacher_without_type_defaults_both(): void
    {
        $this->actingAs(User::find(1), 'api');
        $id = $this->postJson('/api/v1/users', [
            'full_name' => 'Typeless', 'email' => 'typeless@example.org',
            'password' => 'password123', 'role' => 'teacher', 'center_id' => 1,
        ])->assertCreated()->json('data.id');

        $this->assertSame('both', User::find($id)->teacher_type);
    }

    public function test_student_registration_stores_null_type(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'full_name' => 'Student Applicant', 'email' => 'nulltype@example.org',
            'password' => 'password123', 'role' => 'student', 'teacher_type' => 'hifz',
            'phone' => '0612345678', 'birth_date' => '2012-05-10', 'gender' => 'male',
        ])->assertCreated();

        $this->assertNull(DB::table('registration_requests')->where('email', 'nulltype@example.org')->value('teacher_type'));
    }

    public function test_check_rejects_raw_violation(): void
    {
        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'full_name' => 'Raw', 'email' => 'raw@example.org',
            'role' => 'supervisor', 'teacher_type' => 'hifz', 'center_id' => 1,
        ]);
    }
}
