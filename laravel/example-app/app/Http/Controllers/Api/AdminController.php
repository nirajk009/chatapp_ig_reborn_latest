<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Visitor;
use App\Services\RealtimeService;
use App\Support\RealtimeChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    private function resolveAdmin(Request $request): ?Admin
    {
        $token = $request->bearerToken();
        if (!$token) return null;
        return Admin::where('api_token', $token)->first();
    }

    private function formatMessage(Message $msg): array
    {
        return [
            'id' => $msg->id,
            'client_id' => $msg->client_id,
            'conversation_id' => $msg->conversation_id,
            'sender_id' => $msg->sender_id,
            'sender_type' => $msg->sender_type,
            'body' => $msg->body,
            'is_read' => $msg->is_read,
            'created_at' => $msg->created_at?->toIso8601String(),
            'time' => $msg->created_at->format('g:i A'),
        ];
    }

    private function messagePayload(Message $msg, Conversation $conversation): array
    {
        return [
            'message' => $this->formatMessage($msg),
            'conversation' => [
                'id' => $conversation->id,
                'type' => $conversation->type,
            ],
        ];
    }

    private function typingPayload(Admin $admin, Conversation $conversation, bool $typing): array
    {
        return [
            'conversation_id' => $conversation->id,
            'typing' => $typing,
            'sender_role' => 'admin',
            'sender_id' => $admin->id,
            'sender_name' => $admin->name,
        ];
    }

    private function markConversationReadForAdmin(Conversation $conversation): void
    {
        $conversation->messages()
            ->where('sender_type', 'visitor')
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }

    // ─── Login ───

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('email', $request->email)->first();
        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $token = Str::random(64);
        $admin->update(['api_token' => $token]);

        return response()->json([
            'token' => $token,
            'admin' => $admin->publicProfile(),
        ]);
    }

    // ─── List Conversations ───

    public function conversations(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return response()->json(['error' => 'Unauthorized'], 401);

        cache(['admin_last_poll' => now()], now()->addMinutes(1));

        // Get all visitor_admin conversations where admin is participant
        $conversations = Conversation::where('type', 'visitor_admin')
            ->where('participant_two_id', $admin->id)
            ->with('latestMessage')
            ->get()
            ->map(function ($conv) {
                $visitor = Visitor::find($conv->participant_one_id);
                if (!$visitor) return null;

                $latest = $conv->latestMessage;
                $unread = $conv->messages()
                    ->where('is_read', false)
                    ->where('sender_type', 'visitor')
                    ->count();

                return [
                    'conversation_id' => $conv->id,
                    'visitor' => [
                        'id' => $visitor->id,
                        'name' => $visitor->name ?? $visitor->username ?? 'Visitor #' . $visitor->id,
                        'username' => $visitor->username,
                        'email' => $visitor->email,
                        'is_online' => $visitor->isOnline(),
                    ],
                    'last_message' => $latest ? [
                        'body' => Str::limit($latest->body, 50),
                        'created_at' => $latest->created_at?->toIso8601String(),
                        'time' => $latest->created_at->format('g:i A'),
                        'sender_type' => $latest->sender_type,
                    ] : null,
                    'unread_count' => $unread,
                    'updated_at' => $conv->updated_at,
                ];
            })
            ->filter()
            ->sortByDesc('updated_at')
            ->values();

        return response()->json(['conversations' => $conversations]);
    }

    // ─── Get Messages for a conversation ───

    public function getMessages(Request $request, int $visitorId): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return response()->json(['error' => 'Unauthorized'], 401);

        cache(['admin_last_poll' => now()], now()->addMinutes(1));

        $visitor = Visitor::findOrFail($visitorId);
        $conv = Conversation::findOrCreateVisitorAdmin($visitor->id, $admin->id);

        // Mark visitor messages as read
        $this->markConversationReadForAdmin($conv);

        $messages = $conv->messages()->get()->map(fn($m) => $this->formatMessage($m));

        return response()->json([
            'messages' => $messages,
            'conversation_id' => $conv->id,
            'visitor' => [
                'id' => $visitor->id,
                'name' => $visitor->name ?? $visitor->username ?? 'Visitor #' . $visitor->id,
                'username' => $visitor->username,
                'email' => $visitor->email,
                'is_online' => $visitor->isOnline(),
            ],
        ]);
    }

    // ─── Send Message ───

    public function sendMessage(Request $request, int $visitorId, RealtimeService $realtime): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return response()->json(['error' => 'Unauthorized'], 401);

        $request->validate([
            'body' => 'required|string|max:5000',
            'client_id' => 'required|string|max:36',
            'socket_id' => 'nullable|string|max:50',
        ]);

        cache(['admin_last_poll' => now()], now()->addMinutes(1));

        $visitor = Visitor::findOrFail($visitorId);
        $conv = Conversation::findOrCreateVisitorAdmin($visitor->id, $admin->id);

        $message = Message::firstOrCreate(
            ['conversation_id' => $conv->id, 'client_id' => $request->input('client_id')],
            [
                'sender_id' => $admin->id,
                'sender_type' => 'admin',
                'body' => $request->input('body'),
            ]
        );

        if ($message->wasRecentlyCreated) {
            $conv->touch();
            $realtime->publishMessage(
                $conv,
                $this->messagePayload($message, $conv),
                $request->input('socket_id')
            );
        }

        return response()->json([
            'message' => $this->formatMessage($message),
        ], 201);
    }

    public function realtimeAuth(Request $request, RealtimeService $realtime): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return response()->json(['error' => 'Unauthorized'], 401);

        $request->validate([
            'socket_id' => 'required|string|max:50',
            'channel_name' => 'required|string|max:100',
        ]);

        $channelName = (string) $request->input('channel_name');
        $socketId = (string) $request->input('socket_id');
        $presenceUserId = null;
        $presenceUserInfo = null;

        $allowed = $channelName === RealtimeChannels::ADMIN_FEED;

        if (!$allowed && $channelName === RealtimeChannels::ADMIN_PRESENCE) {
            $allowed = true;
            $presenceUserId = 'admin-' . $admin->id;
            $presenceUserInfo = [
                'role' => 'admin',
                'name' => $admin->name,
            ];
        }

        if (!$allowed && preg_match('/^presence-conversation\.(\d+)$/', $channelName, $matches)) {
            $conversation = Conversation::find((int) $matches[1]);

            $allowed = $conversation
                && $conversation->type === 'visitor_admin'
                && $conversation->participant_two_id === $admin->id;

            if ($allowed) {
                $presenceUserId = 'admin-' . $admin->id;
                $presenceUserInfo = [
                    'role' => 'admin',
                    'name' => $admin->name,
                ];
            }
        }

        if (!$allowed) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        try {
            return response()->json(
                $realtime->authorizeChannel($channelName, $socketId, $presenceUserId, $presenceUserInfo)
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Realtime auth failed'], 503);
        }
    }

    public function typing(Request $request, int $visitorId, RealtimeService $realtime): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return response()->json(['error' => 'Unauthorized'], 401);

        $request->validate([
            'typing' => 'required|boolean',
            'socket_id' => 'nullable|string|max:50',
        ]);

        $visitor = Visitor::findOrFail($visitorId);
        $conversation = Conversation::findOrCreateVisitorAdmin($visitor->id, $admin->id);
        cache(['admin_last_poll' => now()], now()->addMinutes(1));

        $realtime->publishTyping(
            $conversation,
            $this->typingPayload($admin, $conversation, (bool) $request->boolean('typing')),
            $request->input('socket_id')
        );

        return response()->json(['status' => 'ok']);
    }

    // ─── Poll (global — for conversation list) ───

    public function markConversationRead(Request $request, int $visitorId): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return response()->json(['error' => 'Unauthorized'], 401);

        $visitor = Visitor::findOrFail($visitorId);
        $conversation = Conversation::findOrCreateVisitorAdmin($visitor->id, $admin->id);
        $this->markConversationReadForAdmin($conversation);

        return response()->json(['status' => 'ok']);
    }

    public function poll(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return response()->json(['error' => 'Unauthorized'], 401);

        cache(['admin_last_poll' => now()], now()->addMinutes(1));

        $sinceId = (int) $request->query('since_id', 0);

        // Get new messages across all admin conversations
        $convIds = Conversation::where('type', 'visitor_admin')
            ->where('participant_two_id', $admin->id)
            ->pluck('id');

        $messages = Message::whereIn('conversation_id', $convIds)
            ->where('id', '>', $sinceId)
            ->orderBy('id')
            ->get()
            ->map(fn($m) => $this->formatMessage($m));

        return response()->json([
            'messages' => $messages,
        ]);
    }

    // ─── Poll specific conversation ───

    public function pollConversation(Request $request, int $visitorId): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return response()->json(['error' => 'Unauthorized'], 401);

        cache(['admin_last_poll' => now()], now()->addMinutes(1));

        $sinceId = (int) $request->query('since_id', 0);

        $visitor = Visitor::findOrFail($visitorId);
        $conv = Conversation::findOrCreateVisitorAdmin($visitor->id, $admin->id);

        // Mark visitor messages as read
        $this->markConversationReadForAdmin($conv);

        $messages = $conv->messages()
            ->where('id', '>', $sinceId)
            ->get()
            ->map(fn($m) => $this->formatMessage($m));

        return response()->json([
            'messages' => $messages,
            'visitor_online' => $visitor->isOnline(),
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return response()->json(['error' => 'Unauthorized'], 401);

        $request->validate([
            'visitor_id' => 'nullable|integer',
        ]);

        cache(['admin_last_poll' => now()], now()->addMinutes(1));

        $visitorOnline = null;
        $visitorId = (int) $request->input('visitor_id', 0);

        if ($visitorId > 0) {
            $visitor = Visitor::find($visitorId);
            $visitorOnline = $visitor ? $visitor->isOnline() : false;
        }

        return response()->json([
            'status' => 'ok',
            'visitor_online' => $visitorOnline,
        ]);
    }
}
