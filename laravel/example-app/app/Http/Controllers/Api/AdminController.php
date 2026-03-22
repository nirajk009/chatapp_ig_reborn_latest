<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Visitor;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    /**
     * Admin login.
     * POST /api/admin/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('email', $request->input('email'))->first();

        if (!$admin || !Hash::check($request->input('password'), $admin->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        // Generate API token
        $token = Str::random(64);
        $admin->update(['api_token' => hash('sha256', $token)]);

        return response()->json([
            'token' => $token,
            'admin' => $admin->publicProfile(),
        ]);
    }

    /**
     * Get all conversations (visitors who have sent messages).
     * GET /api/admin/conversations
     */
    public function conversations(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        cache(['admin_last_poll' => now()], now()->addMinutes(1));

        $visitors = Visitor::whereHas('messages')
            ->withCount(['messages as unread_count' => function ($q) {
                $q->where('sender_type', 'visitor')->where('is_read', false);
            }])
            ->with(['messages' => function ($q) {
                $q->latest()->limit(1);
            }])
            ->get()
            ->map(function ($visitor) {
                $lastMessage = $visitor->messages->first();
                return [
                    'id' => $visitor->id,
                    'name' => $visitor->name ?: 'Visitor #' . $visitor->id,
                    'email' => $visitor->email,
                    'is_online' => $visitor->isOnline(),
                    'unread_count' => $visitor->unread_count,
                    'last_message' => $lastMessage ? [
                        'body' => Str::limit($lastMessage->body, 50),
                        'sender_type' => $lastMessage->sender_type,
                        'time' => $lastMessage->created_at->format('h:i A'),
                        'created_at' => $lastMessage->created_at->toIso8601String(),
                    ] : null,
                    'created_at' => $visitor->created_at->toIso8601String(),
                ];
            })
            ->sortByDesc(function ($v) {
                return $v['last_message']['created_at'] ?? $v['created_at'];
            })
            ->values();

        return response()->json(['conversations' => $visitors]);
    }

    /**
     * Get messages for a specific visitor conversation.
     * GET /api/admin/conversations/{visitor_id}/messages
     */
    public function getMessages(Request $request, int $visitorId): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        cache(['admin_last_poll' => now()], now()->addMinutes(1));

        $visitor = Visitor::findOrFail($visitorId);

        // Mark visitor messages as read
        $visitor->messages()
            ->where('sender_type', 'visitor')
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $messages = $visitor->messages()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($m) => $this->formatMessage($m));

        return response()->json([
            'visitor' => [
                'id' => $visitor->id,
                'name' => $visitor->name ?: 'Visitor #' . $visitor->id,
                'email' => $visitor->email,
                'is_online' => $visitor->isOnline(),
            ],
            'messages' => $messages,
        ]);
    }

    /**
     * Send a message to a visitor as admin.
     * POST /api/admin/conversations/{visitor_id}/messages
     */
    public function sendMessage(Request $request, int $visitorId): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        cache(['admin_last_poll' => now()], now()->addMinutes(1));

        $visitor = Visitor::findOrFail($visitorId);

        $message = $visitor->messages()->create([
            'sender_type' => 'admin',
            'body' => $request->input('body'),
        ]);

        return response()->json([
            'message' => $this->formatMessage($message),
        ], 201);
    }

    /**
     * Poll for new messages across all conversations.
     * GET /api/admin/poll?since_id=123
     */
    public function poll(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        cache(['admin_last_poll' => now()], now()->addMinutes(1));

        $sinceId = (int) $request->query('since_id', 0);

        $messages = Message::where('id', '>', $sinceId)
            ->where('sender_type', 'visitor')
            ->orderBy('id', 'asc')
            ->with('visitor:id,name,email')
            ->get()
            ->map(function ($m) {
                return [
                    'id' => $m->id,
                    'visitor_id' => $m->visitor_id,
                    'visitor_name' => $m->visitor->name ?: 'Visitor #' . $m->visitor_id,
                    'sender_type' => $m->sender_type,
                    'body' => $m->body,
                    'is_read' => $m->is_read,
                    'created_at' => $m->created_at->toIso8601String(),
                    'time' => $m->created_at->format('h:i A'),
                ];
            });

        // Also return total unread count
        $totalUnread = Message::where('sender_type', 'visitor')
            ->where('is_read', false)
            ->count();

        return response()->json([
            'messages' => $messages,
            'total_unread' => $totalUnread,
        ]);
    }

    /**
     * Poll for new messages in a specific conversation.
     * GET /api/admin/conversations/{visitor_id}/poll?since_id=123
     */
    public function pollConversation(Request $request, int $visitorId): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        cache(['admin_last_poll' => now()], now()->addMinutes(1));

        $sinceId = (int) $request->query('since_id', 0);

        $visitor = Visitor::findOrFail($visitorId);

        $messages = $visitor->messages()
            ->where('id', '>', $sinceId)
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn($m) => $this->formatMessage($m));

        // Mark new visitor messages as read
        if ($messages->isNotEmpty()) {
            $visitor->messages()
                ->where('sender_type', 'visitor')
                ->where('is_read', false)
                ->update(['is_read' => true]);
        }

        return response()->json([
            'messages' => $messages,
            'visitor_online' => $visitor->isOnline(),
        ]);
    }

    // ---------- Helpers ----------

    private function resolveAdmin(Request $request): ?Admin
    {
        $token = $request->header('Authorization');
        if (!$token) return null;

        $token = str_replace('Bearer ', '', $token);
        return Admin::where('api_token', hash('sha256', $token))->first();
    }

    private function formatMessage(Message $m): array
    {
        return [
            'id' => $m->id,
            'sender_type' => $m->sender_type,
            'body' => $m->body,
            'is_read' => $m->is_read,
            'created_at' => $m->created_at->toIso8601String(),
            'time' => $m->created_at->format('h:i A'),
        ];
    }
}
