<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreCenterRequest;
use App\Http\Requests\UpdateCenterRequest;
use App\Http\Resources\CenterResource;
use App\Models\Center;
use Illuminate\Http\JsonResponse;

class CenterController extends Controller
{
    /**
     * List centers with group/student counts (20/page).
     *
     * Non-admins always see only their own center.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Center::class);
        $me = auth('api')->user();

        $q = Center::withCount(['groups', 'students'])->orderBy('id');
        if ($me->role !== 'admin') $q->where('id', (int) $me->center_id);

        return $this->ok(CenterResource::collection($q->paginate(20))->response()->getData(true));
    }

    /**
     * Create a center (admin only).
     */
    public function store(StoreCenterRequest $request): JsonResponse
    {
        return $this->created(new CenterResource(Center::create($request->validated())));
    }

    /**
     * Show one center with its group/student counts.
     */
    public function show(Center $center): JsonResponse
    {
        $this->authorize('view', $center);

        return $this->ok(new CenterResource($center->loadCount(['groups', 'students'])));
    }

    /**
     * Update a center's fields.
     */
    public function update(UpdateCenterRequest $request, Center $center): JsonResponse
    {
        $center->update($request->validated());

        return $this->ok(new CenterResource($center->fresh()));
    }

    /**
     * Centers cannot be deleted — always 403 ADMIN_ONLY.
     */
    public function destroy(Center $center): JsonResponse
    {
        $this->authorize('delete', $center);

        return $this->fail('Centers cannot be deleted.', 403);
    }
}
