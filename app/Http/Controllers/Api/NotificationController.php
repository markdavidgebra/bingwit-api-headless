<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // GET ALL MY NOTIFICATIONS
    public function index(Request $request)
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        $unreadCount = Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
        ]);
    }

    // MARK ONE NOTIFICATION AS READ
    public function markRead(Request $request, $id)
    {
        Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->update(['is_read' => true]);

        return response()->json([
            'message' => 'Notification marked as read.',
        ]);
    }

    // MARK ALL NOTIFICATIONS AS READ
    public function markAllRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }

    // DELETE A NOTIFICATION
    public function destroy(Request $request, $id)
    {
        Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'message' => 'Notification deleted.',
        ]);
    }
    // ADMIN — SEND NOTIFICATION TO A USER
    public function send(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'type' => 'required|string',
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'reference_id' => 'nullable|integer',
            'reference_type' => 'nullable|string',
        ]);

        $notification = Notification::create([
            'user_id' => $request->user_id,
            'type' => $request->type,
            'title' => $request->title,
            'body' => $request->body,
            'reference_id' => $request->reference_id,
            'reference_type' => $request->reference_type,
        ]);

        return response()->json([
            'message' => 'Notification sent!',
            'notification' => $notification,
        ], 201);
    }
}