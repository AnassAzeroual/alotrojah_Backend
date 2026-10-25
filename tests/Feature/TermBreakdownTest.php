<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Read-only proof payload behind the four term-result boxes. */
class TermBreakdownTest extends TestCase
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

    public function test_breakdown_explains_hifz_exam_and_general(): void
    {
        $this->actingAs(User::find(1), 'api');

        $response = $this->getJson('/api/v1/term-results/breakdown?student_id=1&term_id=1')
            ->assertOk()
            ->assertJsonPath('data.student.id', 1);

        $hifz = $response->json('data.hifz');
        $this->assertGreaterThan(0, $hifz['sessions']);
        $this->assertCount($hifz['sessions'], $hifz['rows']);
        $this->assertEquals(
            round($hifz['sum'] / $hifz['sessions'], 2),
            $hifz['average'],
        );

        $exam = $response->json('data.exam');
        $this->assertNotNull($exam);
        $this->assertNotEmpty($exam['questions']);
        $this->assertEquals(round(array_sum(array_column($exam['questions'], 'score')), 2), $exam['sum']);
    }

    public function test_breakdown_is_forbidden_across_centers(): void
    {
        $teacher = User::where('role', 'teacher')->where('center_id', '!=', 1)->first();
        if ($teacher === null) {
            $this->markTestSkipped('No cross-center teacher fixture.');
        }

        $this->actingAs($teacher, 'api')
            ->getJson('/api/v1/term-results/breakdown?student_id=1&term_id=1')
            ->assertForbidden();
    }
}
