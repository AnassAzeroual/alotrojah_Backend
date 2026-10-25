<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\TermResult;

/**
 * Bridges a saved `term_batch` exam into the official `term_results` row.
 *
 * `exam_score` is always the exam's overall average. `general_avg` is filled
 * from the exam only when it is empty or still mirrors the previous auto value,
 * so a teacher-entered overall average is never overwritten. `hifz_total`,
 * `murajaa_total`, notes and honor flag are left untouched.
 */
final class TermExamResultSync
{
    public function apply(Exam $exam): void
    {
        $termId = $this->syncableTermId($exam);
        if ($termId === null || $exam->overall_avg === null) {
            return;
        }

        $result = TermResult::firstOrNew([
            'student_id' => $exam->student_id,
            'term_id' => $termId,
        ]);
        $previousExamScore = $result->exam_score;

        $result->season_id = $exam->season_id;
        $result->exam_score = round((float) $exam->overall_avg, 2);
        if ($result->general_avg === null || (
            $previousExamScore !== null
            && abs((float) $result->general_avg - (float) $previousExamScore) < 0.005
        )) {
            $result->general_avg = $result->exam_score;
        }
        $result->save();
    }

    public function forget(Exam $exam): void
    {
        $this->forgetFor((int) $exam->student_id, $this->syncableTermId($exam));
    }

    /**
     * Re-derive the official exam score from the latest remaining term exam,
     * or clear it when no term exam is left.
     */
    public function forgetFor(int $studentId, ?int $termId): void
    {
        if ($termId === null) {
            return;
        }

        $result = TermResult::where('student_id', $studentId)
            ->where('term_id', $termId)
            ->first();
        if ($result === null) {
            return;
        }

        $latest = Exam::where('student_id', $studentId)
            ->where('term_id', $termId)
            ->where('exam_type', 'term_batch')
            ->whereNotNull('overall_avg')
            ->orderByDesc('exam_date')
            ->orderByDesc('id')
            ->first();
        if ($latest !== null) {
            $this->apply($latest);

            return;
        }

        $autoGeneral = $result->general_avg !== null
            && $result->exam_score !== null
            && abs((float) $result->general_avg - (float) $result->exam_score) < 0.005;
        $result->exam_score = null;
        if ($autoGeneral) {
            $result->general_avg = null;
        }

        if ($this->isEmpty($result)) {
            $result->delete();

            return;
        }
        $result->save();
    }

    private function syncableTermId(Exam $exam): ?int
    {
        return $exam->exam_type === 'term_batch' && $exam->term_id !== null
            ? (int) $exam->term_id
            : null;
    }

    private function isEmpty(TermResult $result): bool
    {
        return $result->hifz_total === null
            && $result->murajaa_total === null
            && $result->exam_score === null
            && $result->general_avg === null
            && ($result->teacher_notes === null || $result->teacher_notes === '')
            && ($result->supervisor_note === null || $result->supervisor_note === '')
            && $result->honor_flag === 'none';
    }
}
