<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\Level;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Weekly-exam sheet (owner-directed 2026-10-16): template serves season +
 * active weekly modules + group pupils; batch writes one official `weekly`
 * exam per row (module questions + portion refs), all-or-nothing.
 */
class WeeklyExamTest extends TestCase
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

    private function surahPupil(): int
    {
        $acct = User::create([
            'full_name' => 'Surah Pupil Acct', 'email' => 'surah-weekly@example.org',
            'password_hash' => 'x', 'role' => 'student', 'center_id' => 1, 'is_active' => true,
        ]);

        return $this->postJson('/api/v1/students', [
            'full_name' => 'Surah Pupil', 'memorization_mode' => 'surah',
            'status' => 'active', 'center_id' => 1, 'group_id' => 1,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
            'user_id' => $acct->id,
        ])->assertCreated()->json('data.id');
    }

    private function rows(int $thumnPupil, int $surahPupil): array
    {
        return [
            [
                'student_id' => $thumnPupil,
                'portion' => ['hizb' => 25, 'thumn' => 4],
                'scores' => ['hifz' => 14, 'mowathaba' => 4, 'tajwid' => 2],
            ],
            [
                'student_id' => $surahPupil,
                'portion' => ['surah' => 112, 'ayah_from' => 1, 'ayah_to' => 4],
                'scores' => ['hifz' => 12, 'mowathaba' => 3, 'tajwid' => 2],
            ],
        ];
    }

    public function test_template_serves_season_modules_and_pupils(): void
    {
        // Teacher of the center may run the sheet.
        $data = $this->actingAs(User::find(3), 'api')
            ->getJson('/api/v1/exams/weekly-template?group_id=1')->assertOk()->json('data');
        $this->assertSame(1, $data['season_id']);
        $this->assertSame(['hifz', 'mowathaba', 'tajwid'], array_column($data['modules'], 'code'));
        $this->assertSame([14.0, 4.0, 2.0], array_map('floatval', array_column($data['modules'], 'max_points')));
        $this->assertGreaterThanOrEqual(2, count($data['pupils']));

        // Foreign-center group is refused, even for staff.
        $this->actingAs(User::find(3), 'api')
            ->getJson('/api/v1/exams/weekly-template?group_id=3')->assertForbidden();
    }

    public function test_batch_creates_weekly_exams_with_portions_and_avgs(): void
    {
        $this->actingAs(User::find(1), 'api');
        $surah = $this->surahPupil();
        $r = $this->postJson('/api/v1/exams/weekly-batch', [
            'group_id' => 1, 'exam_date' => '2026-10-16',
            'rows' => $this->rows(1, $surah),
        ])->assertCreated()->json('data');
        $this->assertSame(2, $r['created']);

        foreach ($r['exam_ids'] as $id) {
            $exam = Exam::find($id);
            $this->assertSame('weekly', $exam->exam_type);
            $this->assertSame(1, (int) $exam->season_id);
            $this->assertNull($exam->term_id);
            $this->assertCount(3, $exam->questions);
        }
        $first = Exam::find($r['exam_ids'][0])->questions()->orderBy('question_no')->get();
        $this->assertSame([1, 2, 3], $first->pluck('question_no')->all());
        $this->assertSame(['الحفظ', 'المواظبة / الحضور', 'التجويد'], $first->pluck('prompt_text')->all());
        $this->assertSame(25.0, (float) $first[0]->hizb_ref);
        $this->assertSame(4, (int) $first[0]->thumn_ref);
        $this->assertSame(20.0, (float) Exam::find($r['exam_ids'][0])->overall_avg);
        $second = Exam::find($r['exam_ids'][1])->questions()->orderBy('question_no')->first();
        $this->assertSame(112, (int) $second->surah_ref);
        $this->assertSame(1, (int) $second->ayah_from);
        $this->assertSame(4, (int) $second->ayah_to);
        $this->assertSame(17.0, (float) Exam::find($r['exam_ids'][1])->overall_avg);
    }

    public function test_batch_refuses_duplicate_pupil_same_date(): void
    {
        $this->actingAs(User::find(1), 'api');
        $surah = $this->surahPupil();
        $payload = ['group_id' => 1, 'exam_date' => '2026-10-16', 'rows' => $this->rows(1, $surah)];
        $this->postJson('/api/v1/exams/weekly-batch', $payload)->assertCreated();
        $this->postJson('/api/v1/exams/weekly-batch', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'WEEKLY_EXISTS');
        // All-or-nothing: no second set leaked in.
        $this->assertSame(2, Exam::where('exam_type', 'weekly')->count());
    }

    public function test_batch_validates_rows(): void
    {
        $this->actingAs(User::find(1), 'api');
        $surah = $this->surahPupil();
        $base = ['group_id' => 1, 'exam_date' => '2026-10-16'];

        // Duplicate pupil inside the payload.
        $rows = $this->rows(1, $surah);
        $rows[] = $rows[0];
        $this->postJson('/api/v1/exams/weekly-batch', $base + ['rows' => $rows])
            ->assertUnprocessable();

        // Pupil of another group.
        $rows = $this->rows(1, $surah);
        $rows[0]['student_id'] = 4; // center-2 pupil
        $this->postJson('/api/v1/exams/weekly-batch', $base + ['rows' => $rows])
            ->assertUnprocessable();

        // Missing thumn portion.
        $rows = $this->rows(1, $surah);
        unset($rows[0]['portion']['thumn']);
        $this->postJson('/api/v1/exams/weekly-batch', $base + ['rows' => $rows])
            ->assertUnprocessable();

        // Missing surah portion.
        $rows = $this->rows(1, $surah);
        unset($rows[1]['portion']['surah']);
        $this->postJson('/api/v1/exams/weekly-batch', $base + ['rows' => $rows])
            ->assertUnprocessable();

        // Ayah out of range (Al-Ikhlas has 4).
        $rows = $this->rows(1, $surah);
        $rows[1]['portion']['ayah_to'] = 9;
        $this->postJson('/api/v1/exams/weekly-batch', $base + ['rows' => $rows])
            ->assertUnprocessable();

        // Score over the module weight.
        $rows = $this->rows(1, $surah);
        $rows[0]['scores']['hifz'] = 15;
        $this->postJson('/api/v1/exams/weekly-batch', $base + ['rows' => $rows])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'SCORE_OVER_MAX');

        $this->assertSame(0, Exam::where('exam_type', 'weekly')->count());
    }

    public function test_template_and_batch_require_a_current_season(): void
    {
        $this->actingAs(User::find(1), 'api');
        DB::table('academic_seasons')->update(['is_current' => false]);
        $this->getJson('/api/v1/exams/weekly-template?group_id=1')
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'SEASON_REQUIRED');
        $this->postJson('/api/v1/exams/weekly-batch', [
            'group_id' => 1, 'exam_date' => '2026-10-16',
            'rows' => [[
                'student_id' => 1,
                'portion' => ['hizb' => 1, 'thumn' => 1],
                'scores' => ['hifz' => 10, 'mowathaba' => 3, 'tajwid' => 1],
            ]],
        ])->assertStatus(422)
            ->assertJsonPath('errors.code', 'SEASON_REQUIRED');
    }
}
