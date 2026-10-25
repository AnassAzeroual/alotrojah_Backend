<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The student-first exam builder reads one aggregate brief per pupil. */
class StudentExamBriefTest extends TestCase
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

    public function test_admin_gets_the_builder_brief(): void
    {
        $student = Student::firstOrFail();
        $this->actingAs(User::find(1), 'api');

        $response = $this->getJson("/api/v1/students/{$student->id}/exam-brief")->assertOk();
        $response->assertJsonPath('data.student.id', $student->id);
        $response->assertJsonPath('data.current.mode', $student->memorization_mode);
        $response->assertJsonStructure([
            'data' => [
                'student' => ['id', 'full_name', 'group', 'level', 'memorization_mode'],
                'group' => ['id', 'name', 'teacher'],
                'season' => ['id', 'name'],
                'summary',
                'memorization' => ['last', 'items', 'total_thumn'],
                'exams',
                'current' => ['mode', 'hizb', 'thumn', 'surah', 'ayah_from', 'ayah_to', 'source'],
            ],
        ]);
    }

    public function test_pupil_reads_only_their_own_brief(): void
    {
        $student = Student::firstOrFail();
        $other = Student::where('id', '!=', $student->id)->first();
        $user = User::create([
            'full_name' => 'Brief Pupil', 'email' => 'brief-pupil@example.org',
            'password_hash' => 'x', 'role' => 'student',
            'center_id' => $student->center_id, 'is_active' => true,
        ]);
        $student->update(['user_id' => $user->id]);
        $this->actingAs($user, 'api');

        $this->getJson("/api/v1/students/{$student->id}/exam-brief")->assertOk();
        if ($other !== null) {
            $this->getJson("/api/v1/students/{$other->id}/exam-brief")->assertForbidden();
        }
    }

    public function test_teacher_gets_a_same_center_brief(): void
    {
        $teacher = User::findOrFail(3);
        $student = Student::where('center_id', $teacher->center_id)->firstOrFail();
        $this->actingAs($teacher, 'api');

        $this->getJson("/api/v1/students/{$student->id}/exam-brief")->assertOk();
    }
}
