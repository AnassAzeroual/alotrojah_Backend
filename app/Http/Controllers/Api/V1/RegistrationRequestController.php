<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\RegistrationRequestResource;
use App\Http\Resources\UserResource;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrationRequestController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', RegistrationRequest::class);

        $q = RegistrationRequest::orderByDesc('requested_at');

        return $this->ok(RegistrationRequestResource::collection($q->paginate(20))->response()->getData(true));
    }

    /**
     * Copy the request into users (+ students row when role=student),
     * then drop it from the waiting room.
     */
    public function accept(Request $request, RegistrationRequest $registrationRequest): JsonResponse
    {
        $this->authorize('accept', $registrationRequest);

        $data = $request->validate([
            'center_id' => ['required', 'integer', 'exists:centers,id'],
        ]);
        $centerId = (int) $data['center_id'];

        $user = DB::transaction(function () use ($registrationRequest, $centerId) {
            $user = new User([
                'full_name' => $registrationRequest->full_name,
                'email' => $registrationRequest->email,
                'role' => $registrationRequest->role,
                'phone' => $registrationRequest->phone,
                'center_id' => $centerId,
                'teacher_type' => $registrationRequest->teacher_type,
                'is_active' => true,
            ]);
            $user->password_hash = $registrationRequest->password_hash;
            $user->save();

            if ($registrationRequest->role === 'student') {
                Student::create([
                    'user_id' => $user->id,
                    'center_id' => $centerId,
                    'full_name' => $registrationRequest->full_name,
                    'birth_date' => $registrationRequest->birth_date,
                    'gender' => $registrationRequest->gender,
                    'student_type' => $registrationRequest->birth_date !== null
                        && $registrationRequest->birth_date->diffInYears(now()) >= 18 ? 'adult' : 'child',
                    'enrollment_date' => now()->toDateString(),
                ]);
            }

            $registrationRequest->delete();

            return $user->fresh();
        });

        return $this->ok(new UserResource($user), 'Request approved and account created.');
    }

    public function destroy(RegistrationRequest $registrationRequest): JsonResponse
    {
        $this->authorize('delete', $registrationRequest);

        $registrationRequest->delete();

        return $this->ok(null, 'Request canceled.');
    }
}
