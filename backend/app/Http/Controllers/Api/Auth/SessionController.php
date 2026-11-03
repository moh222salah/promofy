<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Lists and revokes the signed-in user's browser sessions (database session driver). */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $current = $request->hasSession() ? $request->session()->getId() : null;

        $rows = DB::table('sessions')->where('user_id', $request->user()->id)->orderByDesc('last_activity')->get();

        return response()->json(['data' => $rows->map(fn ($row) => [
            'id' => $this->handle($row->id),
            'ip_address' => $row->ip_address,
            'user_agent' => $row->user_agent,
            'last_active_at' => Carbon::createFromTimestamp($row->last_activity)->toIso8601String(),
            'current' => $row->id === $current,
        ])->values()]);
    }

    public function destroy(Request $request, string $handle)
    {
        $current = $request->hasSession() ? $request->session()->getId() : null;

        $rows = DB::table('sessions')->where('user_id', $request->user()->id)->pluck('id');
        $target = $rows->first(fn (string $id) => hash_equals($this->handle($id), $handle) && $id !== $current);
        abort_if($target === null, 404);

        DB::table('sessions')->where('id', $target)->delete();

        return response()->noContent();
    }

    public function destroyOthers(Request $request)
    {
        $current = $request->hasSession() ? $request->session()->getId() : '';
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $current)->delete();

        return response()->noContent();
    }

    /** Opaque identifier so raw session ids never leave the server. */
    private function handle(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }
}
