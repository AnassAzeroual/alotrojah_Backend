<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\GenerateDelegationRequest;
use App\Http\Requests\RedeemDelegationRequest;
use App\Http\Resources\DelegationTokenResource;
use App\Models\DelegationToken;
use App\Models\Group;
use App\Services\DelegationService;
use Illuminate\Http\JsonResponse;

class DelegationController extends Controller
{
    public function index(Group $group): JsonResponse
    {
        $this->authorize('viewGroup', [DelegationToken::class, $group]);

        return $this->ok(DelegationTokenResource::collection(
            $group->delegationTokens()->orderByDesc('id')->limit(50)->get()
        ));
    }

    /** Responsible teacher opens time-boxed access; link goes via WhatsApp. */
    public function generate(GenerateDelegationRequest $request, Group $group, DelegationService $service): JsonResponse
    {
        $token = $service->generate($group, $request->user(), (int) $request->input('minutes'));

        return $this->created([
            'delegation' => new DelegationTokenResource($token),
            'token' => $token->token, // shown ONCE
            'link' => $service->shareLink($token),
            'expires_at' => $token->expires_at,
        ], 'Share this link via WhatsApp.');
    }

    public function redeem(RedeemDelegationRequest $request, DelegationService $service): JsonResponse
    {
        $token = $service->redeem($request->input('token'), $request->user());

        return $this->ok([
            'group_id' => $token->group_id,
            'expires_at' => $token->expires_at,
        ], 'Access granted. You may now enter marks for this group.');
    }

    public function revoke(DelegationToken $delegation): JsonResponse
    {
        $this->authorize('revoke', $delegation);
        $delegation->update(['is_revoked' => true]);

        return $this->ok(null, 'Revoked.');
    }
}
