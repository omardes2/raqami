<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal notification inbox (Sprint 8B Phase 1). Every action is confined to the
 * authenticated User's own rows: the query ALWAYS filters recipient_user_id =
 * auth id (defense in depth on top of recipient-aware RLS), and the read action
 * uses a recipient-scoped lookup — a foreign or unknown id yields 404, never a
 * 403 that would reveal existence. No create and no delete endpoints exist; the
 * only mutation is read_at.
 */
class NotificationController extends Controller
{
    /** GET /api/me/notifications — newest first, paginated; ?unread=1 filters unread. */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 50);

        $query = $this->ownQuery($request)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return NotificationResource::collection($query->paginate($perPage))
            ->response();
    }

    /** GET /api/me/notifications/unread-count — indexed scalar count, no rows loaded. */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->ownQuery($request)->whereNull('read_at')->count();

        return response()->json(['data' => ['unread_count' => $count]]);
    }

    /** PATCH /api/me/notifications/{id}/read — idempotent; mutates only read_at. */
    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $this->ownQuery($request)->findOrFail($id);

        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        return (new NotificationResource($notification))->response();
    }

    /** POST /api/me/notifications/read-all — marks all of the caller's unread read. */
    public function readAll(Request $request): JsonResponse
    {
        $updated = $this->ownQuery($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /** Base query restricted to the authenticated recipient (plus tenant scope + RLS). */
    private function ownQuery(Request $request)
    {
        return Notification::query()->where('recipient_user_id', $request->user()->getKey());
    }
}
