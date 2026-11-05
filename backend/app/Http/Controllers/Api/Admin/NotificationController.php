<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Notifications\EventCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in staff member's own in-app notification inbox. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->user()->notifications();
        if ($request->boolean('unread')) {
            $q = $request->user()->unreadNotifications();
        }
        $page = $q->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($n) => ['id' => $n->id, 'read' => $n->read_at !== null, 'created_at' => $n->created_at] + $n->data)->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'unread' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $request->user()->unreadNotifications()->count()]]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(['data' => ['read' => true]]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['read' => true]]);
    }

    /** Events administrators can configure (default texts, default audience). */
    public function events(): JsonResponse
    {
        \Illuminate\Support\Facades\Gate::authorize('notifications.view');

        return response()->json(['data' => EventCatalog::all()]);
    }
}
