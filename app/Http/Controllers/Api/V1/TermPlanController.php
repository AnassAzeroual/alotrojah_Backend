<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpsertTermPlanRequest;
use App\Http\Resources\TermPlanResource;
use App\Models\Student;
use App\Models\Surah;
use App\Models\Term;
use App\Models\TermPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TermPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TermPlan::class);
        $me = $request->user();

        $q = TermPlan::orderBy('id');
        if ($me->role === 'guardian') {
            $q->whereHas('student.guardian', fn ($g) => $g->where('user_id', $me->id));
        } elseif ($me->role === 'student') {
            $q->whereHas('student', fn ($s) => $s->where('user_id', $me->id));
        } elseif ($me->role !== 'admin') {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $me->center_id));
        }
        if ($request->filled('student_id')) $q->where('student_id', (int) $request->input('student_id'));
        if ($request->filled('term_id')) $q->where('term_id', (int) $request->input('term_id'));

        return $this->ok(TermPlanResource::collection($q->paginate(50))->response()->getData(true));
    }

    /** Create-or-update by UNIQUE(student, term), with range validation. */
    public function upsert(UpsertTermPlanRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();

        $student = Student::findOrFail($data['student_id']);
        if ($me->role !== 'admin' && (int) $student->center_id !== (int) $me->center_id) {
            return $this->fail('Student is in another center.', 403);
        }
        $term = Term::findOrFail($data['term_id']);
        $data['season_id'] = $term->season_id;

        if ($data['plan_mode'] === 'thumn') {
            if ($data['start_hizb'] !== null && $data['end_hizb'] !== null && $data['end_hizb'] < $data['start_hizb']) {
                return $this->fail('End hizb must be >= start hizb.', 422);
            }
            $data['plan_surah_from'] = $data['plan_ayah_from'] = $data['plan_surah_to'] = $data['plan_ayah_to'] = null;
        } else {
            $err = $this->checkSurahRange($data);
            if ($err) return $this->fail($err, 422);
            $data['start_hizb'] = $data['end_hizb'] = null;
        }

        $plan = TermPlan::updateOrCreate(
            ['student_id' => $student->id, 'term_id' => $term->id],
            $data
        );

        return $this->created(new TermPlanResource($plan));
    }

    public function show(TermPlan $termPlan): JsonResponse
    {
        $this->authorize('view', $termPlan);

        return $this->ok(new TermPlanResource($termPlan));
    }

    public function destroy(TermPlan $termPlan): JsonResponse
    {
        $this->authorize('delete', $termPlan);
        $termPlan->delete();

        return $this->ok(null, 'Deleted.');
    }

    private function checkSurahRange(array $d): ?string
    {
        foreach ([['plan_surah_from', 'plan_ayah_from'], ['plan_surah_to', 'plan_ayah_to']] as [$ss, $aa]) {
            if ($d[$ss] === null || $d[$aa] === null) return 'Surah range needs surah + ayah on both ends.';
            $max = (int) Surah::where('id', $d[$ss])->value('ayahs_count');
            if ($d[$aa] < 1 || $d[$aa] > $max) return "Ayah out of range for surah {$d[$ss]} (1-$max).";
        }
        $a = [$d['plan_surah_from'], $d['plan_ayah_from']];
        $b = [$d['plan_surah_to'], $d['plan_ayah_to']];
        if ($a > $b) return 'Range end must be after range start.';

        return null;
    }
}
