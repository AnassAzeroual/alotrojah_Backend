<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
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

        return $this->ok(StudentResource::collection($q->paginate(20))->response()->getData(true));
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();
        if ($me->role !== 'admin') $data['center_id'] = $me->center_id;

        if (! empty($data['group_id'])) {
            $group = Group::findOrFail($data['group_id']);
            if ((int) $group->center_id !== (int) $data['center_id']) {
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
        unset($data['center_id']); // center never moves via update

        if (! empty($data['group_id'])) {
            $group = Group::findOrFail($data['group_id']);
            if ((int) $group->center_id !== (int) $student->center_id) {
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
