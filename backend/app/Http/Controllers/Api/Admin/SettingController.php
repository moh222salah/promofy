<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SettingController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    /** Every group the user may manage, with current (or default) values; secrets are masked. */
    public function index(): JsonResponse
    {
        $out = [];
        foreach ($this->settings->schema() as $group => $def) {
            if (Gate::allows($def['permission']) || Gate::allows('settings.view')) {
                $out[$group] = ['editable' => Gate::allows($def['permission']), 'values' => $this->settings->group($group)];
            }
        }

        return response()->json(['data' => $out]);
    }

    public function show(string $group): JsonResponse
    {
        $def = $this->settings->schema()[$group] ?? abort(404);
        abort_unless(Gate::allows($def['permission']) || Gate::allows('settings.view'), 403);

        return response()->json(['data' => $this->settings->group($group)]);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        $def = $this->settings->schema()[$group] ?? abort(404);
        Gate::authorize($def['permission']);

        $values = $request->validate(['values' => ['required', 'array']])['values'];

        return response()->json(['data' => $this->settings->update($group, $values, $request->user()->id)]);
    }
}
