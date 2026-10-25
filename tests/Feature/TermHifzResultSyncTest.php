<?php

namespace Tests\Feature;

use App\Models\ScoringModule;
use App\Models\Session;
use App\Models\SessionScore;
use App\Models\TermResult;
use App\Services\TermHifzResultSync;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Term Hifz = sum(session weekly totals) / non-cancelled sessions in the term. */
class TermHifzResultSyncTest extends TestCase
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

    public function test_hifz_is_the_session_total_average_across_the_term(): void
    {
        $sessions = Session::where('term_id', 1)->where('status', '!=', 'cancelled')->orderBy('id')->get();
        $this->assertNotEmpty($sessions);

        DB::table('term_results')->where('student_id', 1)->where('term_id', 1)->delete();
        DB::table('session_scores')->where('student_id', 1)->where('term_id', 1)->delete();

        $session = $sessions->first();
        $hifz = ScoringModule::whereNull('center_id')->where('code', 'hifz')->firstOrFail();
        SessionScore::create([
            'student_id' => 1, 'season_id' => $session->season_id, 'term_id' => $session->term_id,
            'week_id' => $session->week_id, 'session_id' => $session->id,
            'log_date' => $session->planned_date ?? now()->toDateString(),
            'module_id' => $hifz->id, 'score' => 14, 'entered_by' => 1,
        ]);

        app(TermHifzResultSync::class)->apply(1, 1);

        $result = TermResult::where('student_id', 1)->where('term_id', 1)->firstOrFail();
        $this->assertEquals(round(14 / $sessions->count(), 2), (float) $result->hifz_total);
    }

    public function test_hifz_overwrites_itself_but_preserves_other_term_fields(): void
    {
        $sessions = Session::where('term_id', 1)->where('status', '!=', 'cancelled')->count();
        DB::table('session_scores')->where('student_id', 1)->where('term_id', 1)->delete();
        DB::table('term_results')->updateOrInsert(
            ['student_id' => 1, 'term_id' => 1],
            [
                'season_id' => 1, 'hifz_total' => 5, 'murajaa_total' => 14,
                'exam_score' => 16, 'general_avg' => 15.5, 'teacher_notes' => 'Keep',
                'honor_flag' => 'none',
            ],
        );

        app(TermHifzResultSync::class)->apply(1, 1);

        $result = TermResult::where('student_id', 1)->where('term_id', 1)->firstOrFail();
        $this->assertEquals(0.0, (float) $result->hifz_total);
        $this->assertEquals(14.0, (float) $result->murajaa_total);
        $this->assertEquals(16.0, (float) $result->exam_score);
        $this->assertEquals(15.5, (float) $result->general_avg);
        $this->assertSame('Keep', $result->teacher_notes);
        $this->assertGreaterThan(0, $sessions);
    }
}
