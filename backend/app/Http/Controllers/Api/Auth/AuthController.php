<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthService $auth): JsonResponse
    {
        abort_unless($request->hasSession(), 400, __('auth.not_stateful'));

        $result = $auth->attempt(
            $request,
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->boolean('remember'),
        );

        if ($result['two_factor']) {
            return response()->json(['two_factor_required' => true]);
        }

        return UserResource::make($result['user']->load('roles'))->withPermissions()->response();
    }

    public function register(RegisterRequest $request, AuthService $auth): JsonResponse
    {
        abort_unless($request->hasSession(), 400, __('auth.not_stateful'));

        $user = $auth->registerCustomer($request->validated(), $request);

        return UserResource::make($user->load('roles'))->withPermissions()->response()->setStatusCode(201);
    }

    public function logout(Request $request, AuthService $auth): Response
    {
        $auth->logout($request);

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return UserResource::make($request->user()->load('roles'))->withPermissions()->response();
    }
}
