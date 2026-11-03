<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\LoginHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'locale' => ['sometimes', Rule::in(config('promofy.locales'))],
            'timezone' => ['sometimes', 'timezone'],
        ]);

        $user = $request->user();
        $user->fill($data)->save();

        return UserResource::make($user->load('roles'))->withPermissions()->response();
    }

    public function loginHistory(Request $request): JsonResponse
    {
        $rows = LoginHistory::query()->where('user_id', $request->user()->id)->latest('id')->limit(50)->get();

        return response()->json(['data' => $rows]);
    }
}
