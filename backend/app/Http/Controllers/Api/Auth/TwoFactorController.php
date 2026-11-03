<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function enable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        return response()->json(['data' => $this->twoFactor->enable($request->user())]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        return response()->json(['data' => ['recovery_codes' => $this->twoFactor->confirm($request->user(), $data['code'])]]);
    }

    public function disable(Request $request)
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $this->twoFactor->disable($request->user());

        return response()->noContent();
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        abort_unless($request->user()->hasTwoFactorEnabled(), 422, __('auth.two_factor_not_pending'));

        return response()->json(['data' => ['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($request->user())]]);
    }

    public function challenge(Request $request, AuthService $auth): JsonResponse
    {
        abort_unless($request->hasSession(), 400, __('auth.not_stateful'));
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $auth->completeTwoFactor($request, $data['code'] ?? null, $data['recovery_code'] ?? null);

        return UserResource::make($user->load('roles'))->withPermissions()->response();
    }
}
