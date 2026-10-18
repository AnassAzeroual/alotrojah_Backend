<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreAnnouncementRequest;
use App\Http\Requests\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * List announcements visible to the caller (20/page).
     *
     * Visibility is audience-scoped (center-wide vs my_students). Filter: audience.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Announcement::class);

        $q = Announcement::visibleTo($request->user())->with('author')->orderByDesc('id');
        if ($request->filled('audience')) $q->where('audience', $request->input('audience'));

        return $this->ok(AnnouncementResource::collection($q->paginate(20))->response()->getData(true));
    }

    /**
     * Publish an announcement (author = caller).
     *
     * audience=my_students targets one group: teachers may only address their
     * own group, supervisors only groups of their center (403 otherwise).
     */
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();

        if (($data['audience'] ?? null) === 'my_students') {
            $group = Group::findOrFail($data['group_id']);
            if ($me->role === 'teacher' && (int) $group->teacher_id !== (int) $me->id) {
                return $this->fail('You may only address your own students.', 403);
            }
            if ($me->role === 'supervisor' && (int) $group->center_id !== (int) $me->center_id) {
                return $this->fail('Group is in another center.', 403);
            }
        }
        $data['author_id'] = $me->id;

        return $this->created(new AnnouncementResource(Announcement::create($data)->load('author')));
    }

    /**
     * Show one announcement with its author.
     */
    public function show(Announcement $announcement): JsonResponse
    {
        $this->authorize('view', $announcement);

        return $this->ok(new AnnouncementResource($announcement->load('author')));
    }

    /**
     * Update an announcement's text/audience (author or admin).
     */
    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $announcement->update($request->validated());

        return $this->ok(new AnnouncementResource($announcement->fresh('author')));
    }

    /**
     * Delete an announcement (author or admin).
     */
    public function destroy(Announcement $announcement): JsonResponse
    {
        $this->authorize('delete', $announcement);
        $announcement->delete();

        return $this->ok(null, 'Deleted.');
    }
}
