<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreMurajaaReviewRequest;
use App\Http\Requests\StoreRevisionLogRequest;
use App\Http\Resources\MurajaaReviewResource;
use App\Http\Resources\RevisionLogResource;
use App\Models\MurajaaReview;
use App\Models\RevisionLog;
use App\Models\Session;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MurajaaController extends Controller
{
    public function indexReviews(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MurajaaReview::class);
        $me = $request->user();

        $q = MurajaaReview::orderBy('week_from');
        if ($me->role !== 'admin') {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $me->center_id));
        }
        if ($request->filled('student_id')) $q->where('student_id', (int) $request->input('student_id'));
        if ($request->filled('term_id')) $q->where('term_id', (int) $request->input('term_id'));

        return $this->ok(MurajaaReviewResource::collection($q->paginate(50))->response()->getData(true));
    }

    /** Official cycle score. Hifz-only teachers are blocked by policy. */
    public function storeReview(StoreMurajaaReviewRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();

        $student = Student::findOrFail($data['student_id']);
        if ($me->role !== 'admin' && (int) $student->center_id !== (int) $me->center_id) {
            return $this->fail('Student is in another center.', 403);
        }
        $span = $data['week_to'] - $data['week_from'] + 1;
        if ($data['week_to'] < $data['week_from'] || $span < 1 || $span > 3) {
            return $this->fail('Review must cover 1 to 3 weeks.', 422);
        }
        $term = \App\Models\Term::findOrFail($data['term_id']);
        $data['season_id'] = $term->season_id;
        $data['entered_by'] = $me->id;

        $review = MurajaaReview::create($data);

        return $this->created(new MurajaaReviewResource($review->fresh()));
    }

    public function showReview(MurajaaReview $murajaaReview): JsonResponse
    {
        $this->authorize('view', $murajaaReview);

        return $this->ok(new MurajaaReviewResource($murajaaReview));
    }

    public function showLog(RevisionLog $revisionLog): JsonResponse
    {
        $this->authorize('view', $revisionLog);

        return $this->ok(new RevisionLogResource($revisionLog));
    }

    public function destroyLog(RevisionLog $revisionLog): JsonResponse
    {
        $this->authorize('delete', $revisionLog);
        $revisionLog->delete();

        return $this->ok(null, 'Deleted.');
    }

    public function destroyReview(MurajaaReview $murajaaReview): JsonResponse
    {
        $this->authorize('delete', $murajaaReview);
        $murajaaReview->delete();

        return $this->ok(null, 'Deleted.');
    }

    public function indexLogs(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RevisionLog::class);
        $me = $request->user();

        $q = RevisionLog::orderBy('id');
        if ($me->role !== 'admin') {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $me->center_id));
        }
        foreach (['student_id', 'session_id', 'week_id'] as $f) {
            if ($request->filled($f)) $q->where($f, (int) $request->input($f));
        }

        return $this->ok(RevisionLogResource::collection($q->paginate(100))->response()->getData(true));
    }

    /** Per-session practice row (official score lives in cycles). */
    public function storeLog(StoreRevisionLogRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();

        $student = Student::findOrFail($data['student_id']);
        if ($me->role !== 'admin' && (int) $student->center_id !== (int) $me->center_id) {
            return $this->fail('Student is in another center.', 403);
        }
        $session = Session::findOrFail($data['session_id']);
        $data += [
            'season_id' => $session->season_id, 'term_id' => $session->term_id,
            'week_id' => $session->week_id, 'log_date' => $session->planned_date ?? now()->toDateString(),
            'entered_by' => $me->id,
        ];

        return $this->created(new RevisionLogResource(RevisionLog::create($data)));
    }
}
