<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreExamRequest;
use App\Http\Requests\UpdateExamRequest;
use App\Http\Requests\WeeklyBatchRequest;
use App\Http\Requests\WeeklyTemplateRequest;
use App\Http\Resources\ExamResource;
use App\Models\AcademicSeason;
use App\Models\Exam;
use App\Models\Group;
use App\Models\QuranVerse;
use App\Models\ScoringModule;
use App\Models\Student;
use App\Models\Term;
use App\Services\TermExamResultSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamController extends Controller
{
    /**
     * List exams with their student (50/page, newest first).
     *
     * Students see only their own exams. Filters: student_id, term_id,
     * season_id, exam_type.
     *
     * @response array{success: bool, message: string|null, data: array{data: list<ExamResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string, per_page: int, to: int|null, total: int}}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Exam::class);
        $me = $request->user();

        $q = Exam::orderByDesc('exam_date')->orderByDesc('id')->with('student');
        if ($me->role === 'student') {
            // C3: a pupil only ever sees their own exams.
            $q->whereHas('student', fn ($s) => $s->where('students.user_id', $me->id));
        } elseif ($me->role !== 'admin') {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $me->center_id));
        }
        foreach (['student_id', 'term_id', 'season_id', 'exam_type'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->input($f));
            }
        }

        return $this->ok(ExamResource::collection($q->paginate(50))->response()->getData(true));
    }

    /**
     * Record one exam for one pupil.
     *
     * final_season and weekly exams carry no term (season comes from the
     * request); the other types derive their season from the term. Teachers
     * always sign as examiner of their own exams. A non-admin writing for
     * another center's pupil gets 403 CROSS_CENTER.
     */
    public function store(StoreExamRequest $request, TermExamResultSync $termResults): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();
        $questions = $data['questions'] ?? [];
        unset($data['questions']);

        $student = Student::findOrFail($data['student_id']);
        if ($me->role !== 'admin' && (int) $student->center_id !== (int) $me->center_id) {
            return $this->fail('Student is in another center.', 403);
        }
        if (in_array($data['exam_type'], ['final_season', 'weekly'], true)) {
            $data['term_id'] = null;
        } else {
            $data['season_id'] = Term::findOrFail($data['term_id'])->season_id;
        }
        // teachers always sign their own exams (examiner = teacher)
        if ($me->role === 'teacher') {
            $data['examiner_id'] = $me->id;
        } else {
            $data['examiner_id'] ??= $me->id;
        }

        if ($questions !== []) {
            $numbers = array_column($questions, 'question_no');
            if (count(array_unique($numbers)) !== count($numbers)) {
                throw ValidationException::withMessages(['questions' => 'Question numbers must be unique.']);
            }
            $total = round(array_sum(array_map(
                fn (array $q): float => (float) $q['max_score'],
                $questions
            )), 2);
            if (abs($total - 20) > 0.009) {
                throw ValidationException::withMessages([
                    'code' => 'WEIGHTS_TOTAL',
                    'weights' => "Weights must total 20 (got $total).",
                ]);
            }
            foreach ($questions as $i => $q) {
                if (isset($q['score']) && (float) $q['score'] > (float) $q['max_score']) {
                    throw ValidationException::withMessages([
                        "questions.$i.score" => 'Score exceeds the question weight.',
                    ]);
                }
            }
        }

        $exam = DB::transaction(function () use ($data, $questions, $termResults) {
            $exam = Exam::create($data);
            foreach ($questions as $row) {
                $row['sort_order'] ??= $row['question_no'];
                $exam->questions()->create($row);
            }
            if ($questions !== []) {
                $scored = array_filter($questions, fn (array $q): bool => isset($q['score']));
                $exam->update([
                    'overall_avg' => $scored === []
                        ? null
                        : round(array_sum(array_map(fn (array $q): float => (float) $q['score'], $scored)), 2),
                ]);
            }
            $termResults->apply($exam);

            return $exam;
        });
        $exam->setRelation('student', $student);

        return $this->created(new ExamResource($exam->load('questions')));
    }

    /**
     * Show one exam with its student and ordered questions.
     */
    public function show(Exam $exam): JsonResponse
    {
        $this->authorize('view', $exam);

        return $this->ok(new ExamResource($exam->load(['student', 'questions' => fn ($q) => $q->orderBy('sort_order')->orderBy('question_no')])));
    }

    /**
     * Update an exam's fields (manage permission).
     *
     * Same term/season derivation as store; term-carrying types refuse a null
     * term with 422 TERM_REQUIRED.
     */
    public function update(UpdateExamRequest $request, Exam $exam, TermExamResultSync $termResults): JsonResponse
    {
        $this->authorize('manageExam', $exam);
        $oldTermId = $exam->term_id;
        $oldType = $exam->exam_type;
        $data = $request->validated();
        // Same term/season derivation as store: final + weekly exams carry
        // no term (season comes from the client); other types derive their
        // season from the term.
        $type = $data['exam_type'] ?? $exam->exam_type;
        if (in_array($type, ['final_season', 'weekly'], true)) {
            $data['term_id'] = null;
        } else {
            $termId = $data['term_id'] ?? $exam->term_id;
            if ($termId === null) {
                return $this->fail('A term is required for this exam type.', 422);
            }
            $data['season_id'] = Term::findOrFail($termId)->season_id;
        }
        $exam->update($data);

        if ($oldType === 'term_batch' && (
            $exam->exam_type !== 'term_batch'
            || (int) $oldTermId !== (int) $exam->term_id
        )) {
            $termResults->forgetFor((int) $exam->student_id, $oldTermId);
        }
        $termResults->apply($exam);

        return $this->ok(new ExamResource($exam->fresh(['questions', 'student'])));
    }

    /**
     * Delete an exam (with its questions).
     */
    public function destroy(Exam $exam, TermExamResultSync $termResults): JsonResponse
    {
        $this->authorize('delete', $exam);
        $exam->delete();
        $termResults->forget($exam);

        return $this->ok(null, 'Deleted.');
    }

    /**
     * Weekly-exam sheet bootstrap (user-directed 2026-10-16): one call gives
     * the page everything — the group's current season, the center's active
     * weekly-total modules (the sheet columns, always totaling 20) and the
     * group's exam-taker pupils with their memorization modes (portion
     * inputs render per pupil).
     *
     * Pupils are a 3-column projection off Student, so the shape is declared
     * (Scramble would otherwise infer the whole Student model).
     *
     * @response array{success: bool, message: null, data: array{season_id: int, modules: list<array{code: string, name_ar: string, max_points: float}>, pupils: list<array{id: int, full_name: string, memorization_mode: 'surah'|'thumn'}>}}
     */
    public function weeklyTemplate(WeeklyTemplateRequest $request): JsonResponse
    {
        $group = $request->group();
        $me = $request->user();
        if ($me->role !== 'admin' && (int) $group->center_id !== (int) $me->center_id) {
            return $this->fail('Group belongs to another center.', 403);
        }

        $season = $this->currentSeasonFor((int) $group->center_id);
        if (! $season) {
            return $this->fail('A current season is required for weekly exams.', 422);
        }
        $modules = $this->weeklyModules((int) $group->center_id);
        $total = round($modules->sum(fn ($m) => (float) $m->max_points), 2);
        if (abs($total - 20) > 0.009) {
            return $this->fail("Weights must total 20 (got $total) — fix the center's scoring modules.", 422);
        }

        $pupils = Student::where('group_id', $group->id)
            ->whereIn('status', ['active', 'paused'])->orderBy('full_name')
            ->get(['id', 'full_name', 'memorization_mode']);

        return $this->ok([
            'season_id' => $season->id,
            'modules' => $modules->map(fn ($m) => [
                'code' => $m->code, 'name_ar' => $m->name_ar, 'max_points' => (float) $m->max_points,
            ])->values(),
            'pupils' => $pupils,
        ]);
    }

    /**
     * Weekly-exam batch save: one official `weekly` exam per pupil row, each
     * with one question per active weekly module (prompt = module name,
     * weight = module points) scored from the sheet; the memorized portion
     * rides the first question's ref columns (thumn: hizb_ref+thumn_ref,
     * surah: surah_ref+ayah_from/ayah_to). All-or-nothing.
     */
    public function weeklyBatch(WeeklyBatchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();
        $group = Group::findOrFail($data['group_id']);
        if ($me->role !== 'admin' && (int) $group->center_id !== (int) $me->center_id) {
            return $this->fail('Group belongs to another center.', 403);
        }

        $season = $this->currentSeasonFor((int) $group->center_id);
        if (! $season) {
            return $this->fail('A current season is required for weekly exams.', 422);
        }
        $modules = $this->weeklyModules((int) $group->center_id);
        $total = round($modules->sum(fn ($m) => (float) $m->max_points), 2);
        if (abs($total - 20) > 0.009) {
            return $this->fail("Weights must total 20 (got $total) — fix the center's scoring modules.", 422);
        }
        $byCode = $modules->keyBy('code');

        $pupils = Student::whereIn('id', collect($data['rows'])->pluck('student_id')->all())
            ->get()->keyBy('id');
        $surahMax = QuranVerse::whereIn('sura_no', collect($data['rows'])
            ->map(fn ($r) => $r['portion']['surah'] ?? null)->filter()->unique()->all())
            ->groupBy('sura_no')->selectRaw('sura_no, MAX(ayah_no) as m')->pluck('m', 'sura_no');

        $errors = [];
        $seen = [];
        foreach ($data['rows'] as $i => $row) {
            $pupil = $pupils[$row['student_id']] ?? null;
            if (! $pupil || (int) $pupil->group_id !== (int) $group->id) {
                $errors["rows.$i.student_id"] = 'Pupil is not in this group.';
            }
            if (isset($seen[$row['student_id']])) {
                $errors["rows.$i.student_id"] = 'Duplicate pupil in this batch.';
            }
            $seen[$row['student_id']] = true;
            foreach (array_keys($row['scores']) as $code) {
                if (! isset($byCode[$code])) {
                    $errors["rows.$i.scores.$code"] = 'Unknown scoring module.';
                }
            }
            if ($pupil) {
                $portion = $row['portion'];
                if ($pupil->memorization_mode === 'thumn') {
                    if (empty($portion['hizb'])) {
                        $errors["rows.$i.portion.hizb"] = 'Hizb is required.';
                    }
                    if (empty($portion['thumn'])) {
                        $errors["rows.$i.portion.thumn"] = 'Thumn is required.';
                    }
                } else {
                    if (empty($portion['surah'])) {
                        $errors["rows.$i.portion.surah"] = 'Surah is required.';
                    } elseif (! QuranVerse::where('sura_no', $portion['surah'])->exists()) {
                        $errors["rows.$i.portion.surah"] = 'Unknown surah.';
                    }
                    if (empty($portion['ayah_from'])) {
                        $errors["rows.$i.portion.ayah_from"] = 'Starting ayah is required.';
                    } elseif (! empty($portion['surah'])) {
                        $max = (int) ($surahMax[$portion['surah']] ?? 0);
                        foreach (['ayah_from', 'ayah_to'] as $a) {
                            if (! empty($portion[$a]) && ($portion[$a] < 1 || $portion[$a] > $max)) {
                                $errors["rows.$i.portion.$a"] = "Ayah out of range (1-$max).";
                            }
                        }
                        if (! empty($portion['ayah_from']) && ! empty($portion['ayah_to'])
                            && $portion['ayah_to'] < $portion['ayah_from']) {
                            $errors["rows.$i.portion.ayah_to"] = 'Must be after ayah_from.';
                        }
                    }
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($data['rows'] as $row) {
            foreach ($row['scores'] as $code => $score) {
                if ($score !== null && (float) $score > (float) $byCode[$code]->max_points) {
                    return $this->fail('Score exceeds the question weight.', 422);
                }
            }
            if (Exam::where('exam_type', 'weekly')->where('student_id', $row['student_id'])
                ->whereDate('exam_date', $data['exam_date'])->exists()) {
                return $this->fail('A weekly exam already exists for this pupil on this date.', 422);
            }
        }

        $ids = DB::transaction(function () use ($data, $season, $me, $modules, $pupils) {
            $out = [];
            foreach ($data['rows'] as $row) {
                $pupil = $pupils[$row['student_id']];
                $exam = Exam::create([
                    'student_id' => $pupil->id,
                    'exam_type' => 'weekly',
                    'season_id' => $season->id,
                    'term_id' => null,
                    'exam_date' => $data['exam_date'],
                    'examiner_id' => $me->id,
                ]);
                $first = true;
                $qno = 0;
                foreach ($modules as $m) {
                    $q = [
                        'question_no' => ++$qno,
                        'prompt_text' => $m->name_ar,
                        'max_score' => $m->max_points,
                        'score' => $row['scores'][$m->code] ?? null,
                        'sort_order' => $m->sort_order,
                    ];
                    if ($first) {
                        $first = false;
                        $portion = $row['portion'];
                        if ($pupil->memorization_mode === 'thumn') {
                            $q['hizb_ref'] = $portion['hizb'];
                            $q['thumn_ref'] = $portion['thumn'];
                        } else {
                            $q['surah_ref'] = $portion['surah'];
                            $q['ayah_from'] = $portion['ayah_from'];
                            $q['ayah_to'] = $portion['ayah_to'] ?? null;
                        }
                    }
                    $exam->questions()->create($q);
                }
                $has = $exam->questions()->whereNotNull('score')->exists();
                $exam->update(['overall_avg' => $has
                    ? round((float) $exam->questions()->whereNotNull('score')->sum('score'), 2) : null]);
                $out[] = $exam->id;
            }

            return $out;
        });

        return $this->created(['created' => count($ids), 'exam_ids' => $ids]);
    }

    /**
     * Current season for a center lane: the center's own current season,
     * falling back to the legacy shared (NULL-center) one.
     */
    private function currentSeasonFor(int $centerId): ?AcademicSeason
    {
        return AcademicSeason::where('is_current', true)
            ->where(fn ($q) => $q->where('center_id', $centerId)->orWhereNull('center_id'))
            ->orderByRaw('`center_id` IS NULL')->first();
    }

    /**
     * The sheet columns: active weekly-total modules of the center's
     * effective set, in sort order (14+4+2 on defaults = exactly 20).
     */
    private function weeklyModules(int $centerId)
    {
        return ScoringModule::effectiveFor($centerId)->values()
            ->filter(fn ($m) => $m->scope === 'weekly' && (bool) $m->is_in_weekly_total && (bool) $m->is_active)
            ->sortBy('sort_order')->values();
    }
}
