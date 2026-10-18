<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreNotificationRequest;
use App\Http\Requests\UpdateNotificationStatusRequest;
use App\Http\Resources\NotificationResource;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * List notification logs (50/page, newest first).
     *
     * Non-admins see only their center's. Filters: status, channel.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', NotificationLog::class);
        $me = $request->user();

        $q = NotificationLog::orderByDesc('id');
        if ($me->role !== 'admin') {
            $q->whereHas('sentBy', fn ($u) => $u->where('users.center_id', (int) $me->center_id));
        }
        if ($request->filled('status')) $q->where('status', $request->input('status'));
        if ($request->filled('channel')) $q->where('channel', $request->input('channel'));

        return $this->ok(NotificationResource::collection($q->paginate(50))->response()->getData(true));
    }

    /** Queue a WhatsApp message; teacher taps the wa_link on their phone to send. */
    public function store(StoreNotificationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['channel'] ??= 'whatsapp';
        $data['status'] = 'queued';
        $data['sent_by'] = $request->user()->id;

        return $this->created(new NotificationResource(NotificationLog::create($data)));
    }

    /**
     * Show one notification log.
     */
    public function show(NotificationLog $notification): JsonResponse
    {
        $this->authorize('view', $notification);

        return $this->ok(new NotificationResource($notification));
    }

    /**
     * Mark a notification queued/sent/failed.
     *
     * Setting status=sent stamps sent_at; any other status clears it.
     */
    public function mark(UpdateNotificationStatusRequest $request, NotificationLog $notification): JsonResponse
    {
        $notification->update([
            'status' => $request->input('status'),
            'sent_at' => $request->input('status') === 'sent' ? now() : null,
        ]);

        return $this->ok(new NotificationResource($notification->fresh()));
    }

    /**
     * Delete a notification log.
     */
    public function destroy(NotificationLog $notification): JsonResponse
    {
        $this->authorize('delete', $notification);
        $notification->delete();

        return $this->ok(null, 'Deleted.');
    }
}
