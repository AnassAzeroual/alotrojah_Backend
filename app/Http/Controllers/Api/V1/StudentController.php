<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Center;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Student::class);
        $me = $request->user();

        $q = Student::with(['group'])->orderBy('id');

        if ($me->role === 'student') {
            $q->where('user_id', $me->id);
        } elseif ($me->role !== 'admin') {
            $q->forCenter((int) $me->center_id);
        } elseif ($request->filled('center_id')) {
            $q->where('center_id', (int) $request->input('center_id'));
        }
        foreach (['group_id', 'level_id', 'status', 'memorization_mode'] as $f) {
            if ($request->filled($f)) $q->where($f, $request->input($f));
        }
        if ($request->filled('q')) $q->where('full_name', 'like', '%'.$request->input('q').'%');
        // Pupils with no group assigned.
        if ($request->boolean('unassigned')) $q->whereNull('group_id');

        // §2.2: honor per_page (clamped) instead of silently truncating at 20.
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return $this->ok(StudentResource::collection($q->paginate($perPage))->response()->getData(true));
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();
        if ($me->role !== 'admin') $data['center_id'] = $me->center_id;
        // §2.1: an admin who omits the center on a single-center deployment
        // gets the sole center instead of a NULL-center (orphan) pupil.
        if ($me->role === 'admin' && empty($data['center_id']) && Center::count() === 1) {
            $data['center_id'] = Center::value('id');
        }

        if (! empty($data['group_id'])) {
            $group = Group::findOrFail($data['group_id']);
            // NULL-center pupils may sit in a group until the admin repairs
            // the center (§2.1); the cross-center check only fires when both
            // sides are set.
            if (! empty($data['center_id']) && (int) $group->center_id !== (int) $data['center_id']) {
                return $this->fail('Group belongs to another center.', 422);
            }
            $data['level_id'] ??= $group->level_id;
        }

        return $this->created(new StudentResource(Student::create($data)));
    }

    public function show(Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        return $this->ok(new StudentResource($student->load(['group'])));
    }

    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $data = $request->validated();
        // §2.1: only non-admins are barred from moving the center; admins may
        // repair NULL-center pupils through the UI.
        if ($request->user()->role !== 'admin') unset($data['center_id']);

        if (! empty($data['group_id'])) {
            $group = Group::findOrFail($data['group_id']);
            $targetCenter = $data['center_id'] ?? $student->center_id;
            if ((int) $group->center_id !== (int) $targetCenter) {
                return $this->fail('Group belongs to another center.', 422);
            }
        }

        $student->update($data);

        return $this->ok(new StudentResource($student->fresh(['group'])));
    }

    public function destroy(Student $student): JsonResponse
    {
        $this->authorize('delete', $student);
        $student->delete();

        return $this->ok(null, 'Deleted.');
    }
}
