<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);
        $me = $request->user();

        $q = User::orderBy('id');
        if ($me->role !== 'admin') $q->forCenter((int) $me->center_id);
        elseif ($request->filled('center_id')) $q->where('center_id', (int) $request->input('center_id'));
        if ($request->filled('role')) $q->where('role', $request->input('role'));
        if ($request->filled('q')) $q->where('full_name', 'like', '%'.$request->input('q').'%');

        return $this->ok(UserResource::collection($q->paginate(20))->response()->getData(true));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();

        if ($me->role !== 'admin') {
            if (($data['role'] ?? null) === 'admin') return $this->fail('Only admin can create admins.', 403);
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

        if ($self) unset($data['role'], $data['center_id'], $data['is_active'], $data['teacher_type']);
        if ($me->role !== 'admin') {
            if (($data['role'] ?? null) === 'admin') return $this->fail('Only admin can assign admin role.', 403);
            unset($data['center_id']);
        }
        if (array_key_exists('password', $data)) {
            if ($data['password'] === null) unset($data['password']);
            else { $data['password_hash'] = Hash::make($data['password']); unset($data['password']); }
        }

        $user->update($data);

        return $this->ok(new UserResource($user->fresh()));
    }

    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);
        $user->delete();

        return $this->ok(null, 'Deleted.');
    }
}
