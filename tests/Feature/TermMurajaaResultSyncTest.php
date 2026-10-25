<?php

namespace Tests\Feature;

use App\Models\MurajaaReview;
use App\Models\Session;
use App\Models\TermResult;
use App\Services\TermMurajaaResultSync;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Term Murajaa = average of the official review cycle scores. */
class TermMurajaaResultSyncTest extends TestCase
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

    public function test_murajaa_is_the_review_cycle_average_and_preserves_other_fields(): void
    {
        DB::table('term_results')->updateOrInsert(
            ['student_id' => 1, 'term_id' => 1],
            [
                'season_id' => 1, 'hifz_total' => 0.9, 'exam_score' => 16,
                'general_avg' => 16, 'teacher_notes' => 'Keep', 'honor_flag' => 'none',
            ],
        );
        DB::table('murajaa_reviews')->where('student_id', 1)->where('term_id', 1)->delete();

        $session = Session::where('term_id', 1)->where('status', '!=', 'cancelled')->firstOrFail();
        foreach ([16, 18] as $i => $score) {
            MurajaaReview::create([
                'student_id' => 1, 'season_id' => 1, 'term_id' => 1,
                'week_from' => $i + 1, 'week_to' => $i + 1, 'weeks_covered' => 1,
                'session_id' => $session->id, 'score' => $score, 'entered_by' => 1,
                'reviewed_at' => $session->planned_date ?? now()->toDateString(),
            ]);
        }

        app(TermMurajaaResultSync::class)->apply(1, 1);

        $result = TermResult::where('student_id', 1)->where('term_id', 1)->firstOrFail();
        $this->assertEquals(17.0, (float) $result->murajaa_total);
        $this->assertEquals(0.9, (float) $result->hifz_total);
        $this->assertEquals(16.0, (float) $result->exam_score);
        $this->assertEquals(16.0, (float) $result->general_avg);
        $this->assertSame('Keep', $result->teacher_notes);
    }

    public function test_deleting_the_last_review_clears_murajaa(): void
    {
        DB::table('murajaa_reviews')->where('student_id', 1)->where('term_id', 1)->delete();
        DB::table('term_results')->where('student_id', 1)->where('term_id', 1)->delete();
        $session = Session::where('term_id', 1)->firstOrFail();
        $review = MurajaaReview::create([
            'student_id' => 1, 'season_id' => 1, 'term_id' => 1,
            'week_from' => 1, 'week_to' => 1, 'weeks_covered' => 1,
            'session_id' => $session->id, 'score' => 15, 'entered_by' => 1,
            'reviewed_at' => $session->planned_date ?? now()->toDateString(),
        ]);
        app(TermMurajaaResultSync::class)->apply(1, 1);
        $review->delete();
        app(TermMurajaaResultSync::class)->apply(1, 1);

        $result = TermResult::where('student_id', 1)->where('term_id', 1)->firstOrFail();
        $this->assertNull($result->murajaa_total);
    }
}
