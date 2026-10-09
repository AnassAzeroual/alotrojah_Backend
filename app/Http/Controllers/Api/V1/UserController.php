<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Announcement;
use App\Models\DelegationToken;
use App\Models\Exam;
use App\Models\Group;
use App\Models\MurajaaReview;
use App\Models\RevisionLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);
        $me = $request->user();

        $q = User::orderBy('id');
        if ($me->role !== 'admin') {
            $q->forCenter((int) $me->center_id);
        } elseif ($request->filled('center_id')) {
            $q->where('center_id', (int) $request->input('center_id'));
        }
        if ($request->filled('role')) {
            $q->where('role', $request->input('role'));
        }
        if ($request->filled('q')) {
            $q->where('full_name', 'like', '%'.$request->input('q').'%');
        }
        // Free teachers: no ACTIVE group assigned (inactive groups don't count).
        if ($request->boolean('unassigned')) {
            $q->whereNotExists(function ($sq) {
                $sq->select(DB::raw(1))->from('groups')
                    ->whereColumn('groups.teacher_id', 'users.id')
                    ->where('groups.is_active', true);
            });
        }

        return $this->ok(UserResource::collection($q->paginate(20))->response()->getData(true));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();

        if ($me->role !== 'admin') {
            if (($data['role'] ?? null) === 'admin') {
                return $this->fail('Only admin can create admins.', 403);
            }
            $data['center_id'] = $me->center_id;
        }

        $data['password_hash'] = Hash::make($data['password']);
        unset($data['password']);

        return $this->created(new UserResource(User::create($data)));
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return $this->ok(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();
        $self = (int) $user->id === (int) $me->id;
        $deactivating = array_key_exists('is_active', $data) && ! $data['is_active'];

        if ($self) {
            unset($data['role'], $data['center_id'], $data['teacher_type']);
            if ($deactivating && $me->role === 'admin') {
                return $this->fail('Admins cannot deactivate their own account.', 422);
            }
            // Self-service is one-way: anyone may switch themselves OFF
            // (reactivation needs another admin); anything else is stripped.
            if (! $deactivating) {
                unset($data['is_active']);
            }
        }
        if ($me->role !== 'admin') {
            if (($data['role'] ?? null) === 'admin') {
                return $this->fail('Only admin can assign admin role.', 403);
            }
            unset($data['center_id']);
        }
        if (array_key_exists('password', $data)) {
            if ($data['password'] === null) {
                unset($data['password']);
            } else {
                $data['password_hash'] = Hash::make($data['password']);
                unset($data['password']);
            }
        }
        if ($deactivating && $user->role === 'admin'
            && ! User::where('role', 'admin')->where('is_active', true)->where('id', '!=', $user->id)->exists()) {
            return $this->fail('The last active admin cannot be deactivated.', 422);
        }

        $user->update($data);

        return $this->ok(new UserResource($user->fresh()));
    }

    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);
        if ($user->role === 'admin') {
            return $this->fail('Admin accounts cannot be deleted.', 422);
        }

        $groups = Group::where('teacher_id', $user->id)->get(['id', 'name']);
        $counts = $this->referenceCounts($user->id);
        // NOT NULL world: groups and transferable history cannot be nulled —
        // anything owned means the deleter must go through the replacer flow.
        // Only tokens + authored announcements are still deletable inline.
        if ($groups->isNotEmpty() || $counts['exams'] > 0 || $counts['entered_scores'] > 0) {
            return $this->fail('This teacher owns groups or history — choose a replacer.', 422, [
                'code' => 'NEED_REPLACER',
                'teacher' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'teacher_type' => $user->teacher_type,
                    'center_id' => $user->center_id,
                ],
                'groups' => $groups,
                'counts' => $counts,
            ]);
        }

        DB::transaction(function () use ($user) {
            Announcement::where('author_id', $user->id)->delete();
            $this->deleteTokens($user->id);
            $user->delete();
        });

        return $this->ok(null, 'Deleted.');
    }

    /**
     * Delete a teacher by transferring their groups + history to a replacer.
     * Replacer must be: role=teacher, active, same center, same teacher_type
     * (or 'both' — strict, so a 'both' teacher needs a 'both' replacer), and
     * free (no active group assigned). Announcements authored + delegation
     * tokens involving the deleted teacher are removed.
     */
    public function replace(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);
        if ($user->role === 'admin') {
            return $this->fail('Admin accounts cannot be deleted.', 422);
        }

        $data = $request->validate(['replacer_id' => ['required', 'integer', 'exists:users,id']]);
        $replacer = User::findOrFail($data['replacer_id']);

        if ((int) $replacer->id === (int) $user->id) {
            return $this->fail('The replacer cannot be the deleted teacher.', 422);
        }
        if ($replacer->role !== 'teacher' || ! $replacer->is_active) {
            return $this->fail('The replacer must be an active teacher.', 422);
        }
        if ((int) $replacer->center_id !== (int) $user->center_id) {
            return $this->fail('The replacer must be in the same center.', 422);
        }
        if ($replacer->teacher_type !== $user->teacher_type && $replacer->teacher_type !== 'both') {
            return $this->fail('The replacer must have the same type (or both).', 422);
        }
        if (Group::where('teacher_id', $replacer->id)->where('is_active', true)->exists()) {
            return $this->fail('The replacer already owns an active group.', 422);
        }

        $moved = [];
        DB::transaction(function () use ($user, $replacer, &$moved) {
            $moved['groups'] = Group::where('teacher_id', $user->id)->update(['teacher_id' => $replacer->id]);
            $moved['exams'] = $this->clearTransferableReferences($user->id, $replacer->id);
            $moved['announcements'] = Announcement::where('author_id', $user->id)->delete();
            $moved['tokens'] = $this->deleteTokens($user->id);
            $user->delete();
        });

        return $this->ok(['replacer_id' => $replacer->id, 'moved' => $moved], 'Replaced and deleted.');
    }

    /** Counts of rows referencing the user (for the NEED_REPLACER dialog). */
    private function referenceCounts(int $userId): array
    {
        return [
            'exams' => Exam::where('examiner_id', $userId)->count(),
            'entered_scores' => RevisionLog::where('entered_by', $userId)->count()
                + MurajaaReview::where('entered_by', $userId)->count(),
            'announcements' => Announcement::where('author_id', $userId)->count(),
            'tokens' => DelegationToken::where('granter_teacher_id', $userId)
                ->orWhere('used_by_teacher_id', $userId)->count(),
        ];
    }

    /**
     * Point transferable history references at the replacer $targetId.
     * Returns the exams count for the moved summary.
     */
    private function clearTransferableReferences(int $userId, int $targetId): int
    {
        $exams = Exam::where('examiner_id', $userId)->update(['examiner_id' => $targetId]);
        RevisionLog::where('entered_by', $userId)->update(['entered_by' => $targetId]);
        MurajaaReview::where('entered_by', $userId)->update(['entered_by' => $targetId]);

        return $exams;
    }

    private function deleteTokens(int $userId): int
    {
        return DelegationToken::where('granter_teacher_id', $userId)
            ->orWhere('used_by_teacher_id', $userId)->delete();
    }
}
