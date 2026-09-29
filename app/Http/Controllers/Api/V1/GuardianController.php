<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreGuardianRequest;
use App\Http\Requests\UpdateGuardianRequest;
use App\Http\Resources\GuardianResource;
use App\Models\Guardian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuardianController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Guardian::class);
        $me = $request->user();

        $q = Guardian::withCount('students')->orderBy('id');
        if ($me->role === 'guardian') {
            $q->where('user_id', $me->id);
        } elseif ($me->role !== 'admin') {
            $q->whereHas('students', fn ($s) => $s->where('students.center_id', (int) $me->center_id));
        } elseif ($request->filled('center_id')) {
            $q->whereHas('students', fn ($s) => $s->where('students.center_id', (int) $request->input('center_id')));
        }

        return $this->ok(GuardianResource::collection($q->paginate(20))->response()->getData(true));
    }

    public function store(StoreGuardianRequest $request): JsonResponse
    {
        return $this->created(new GuardianResource(Guardian::create($request->validated())));
    }

    public function show(Guardian $guardian): JsonResponse
    {
        $this->authorize('view', $guardian);

        return $this->ok(new GuardianResource($guardian->loadCount('students')));
    }

    public function update(UpdateGuardianRequest $request, Guardian $guardian): JsonResponse
    {
        $guardian->update($request->validated());

        return $this->ok(new GuardianResource($guardian->fresh()));
    }

    public function destroy(Guardian $guardian): JsonResponse
    {
        $this->authorize('delete', $guardian);
        $guardian->delete();

        return $this->ok(null, 'Deleted.');
    }
}
