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

class VisitorChatController extends Controller
{
    // ─── Auth helpers ───

    private function resolveVisitor(Request $request): ?Visitor
    {
        $token = $request->header('X-Visitor-Token');
        if (!$token) return null;
        return Visitor::where('token', $token)->first();
    }

    private function formatMessage(Message $msg): array
    {
        return [
            'id' => $msg->id,
            'conversation_id' => $msg->conversation_id,
            'sender_id' => $msg->sender_id,
            'sender_type' => $msg->sender_type,
            'body' => $msg->body,
            'is_read' => $msg->is_read,
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

    private function markConversationReadForVisitor(Conversation $conversation, Visitor $visitor): void
    {
        $conversation->messages()
            ->where('is_read', false)
            ->where(function ($query) use ($visitor) {
                $query->where('sender_type', '!=', 'visitor')
                    ->orWhere('sender_id', '!=', $visitor->id);
            })
            ->update(['is_read' => true]);
    }

    // ─── Init (anonymous session) ───

    public function init(Request $request): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);

        if (!$visitor) {
            $visitor = Visitor::create([
                'token' => Str::random(64),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_seen_at' => now(),
            ]);
        } else {
            $visitor->update(['last_seen_at' => now()]);
        }

        $admin = Admin::first();

        // Auto-create visitor↔admin conversation
        $conv = Conversation::findOrCreateVisitorAdmin($visitor->id, $admin ? $admin->id : 1);

        return response()->json([
            'visitor' => [
                'id' => $visitor->id,
                'token' => $visitor->token,
                'username' => $visitor->username,
                'name' => $visitor->name,
                'email' => $visitor->email,
                'is_logged_in' => $visitor->password !== null,
            ],
            'admin' => $admin ? [
                'id' => $admin->id,
                'name' => $admin->name,
                'avatar_url' => null,
            ] : null,
            'conversation_id' => $conv->id,
        ]);
    }

    // ─── Signup ───

    public function signup(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:191',
            'password' => 'required|string|min:6|max:100',
            'username' => 'required|string|min:3|max:30|regex:/^[a-zA-Z0-9_]+$/',
        ]);

        // Check uniqueness
        if (Visitor::where('email', $request->email)->exists()) {
            return response()->json(['error' => 'Email already registered'], 422);
        }
        if (Visitor::where('username', $request->username)->exists()) {
            return response()->json(['error' => 'Username already taken'], 422);
        }

        // If visitor has existing anonymous session, upgrade it
        $visitor = $this->resolveVisitor($request);

        if ($visitor && !$visitor->password) {
            $visitor->update([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'username' => $request->username,
            ]);
        } else {
            $visitor = Visitor::create([
                'token' => Str::random(64),
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'username' => $request->username,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_seen_at' => now(),
            ]);
        }

        // Ensure admin conversation exists
        $admin = Admin::first();
        Conversation::findOrCreateVisitorAdmin($visitor->id, $admin ? $admin->id : 1);

        return response()->json([
            'visitor' => [
                'id' => $visitor->id,
                'token' => $visitor->token,
                'username' => $visitor->username,
                'name' => $visitor->name,
                'email' => $visitor->email,
                'is_logged_in' => true,
            ],
        ], 201);
    }

    // ─── Login ───

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Check if it's an admin
        $admin = Admin::where('email', $request->email)->first();
        if ($admin && Hash::check($request->password, $admin->password)) {
            $token = Str::random(64);
            $admin->update(['api_token' => $token]);

            return response()->json([
                'is_admin' => true,
                'token' => $token,
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                ],
            ]);
        }

        // Otherwise check visitor
        $visitor = Visitor::where('email', $request->email)->first();

        if (!$visitor || !$visitor->password || !Hash::check($request->password, $visitor->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $visitor->update(['last_seen_at' => now()]);

        return response()->json([
            'visitor' => [
                'id' => $visitor->id,
                'token' => $visitor->token,
                'username' => $visitor->username,
                'name' => $visitor->name,
                'email' => $visitor->email,
                'is_logged_in' => true,
            ],
        ]);
    }

    // ─── Get Messages (admin conversation) ───

    public function getMessages(Request $request): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) return response()->json(['error' => 'Invalid token'], 401);

        $visitor->update(['last_seen_at' => now()]);

        $admin = Admin::first();
        $conv = Conversation::findOrCreateVisitorAdmin($visitor->id, $admin ? $admin->id : 1);
        $this->markConversationReadForVisitor($conv, $visitor);

        $messages = $conv->messages()->get()->map(fn($m) => $this->formatMessage($m));

        return response()->json([
            'messages' => $messages,
            'conversation_id' => $conv->id,
        ]);
    }

    // ─── Send Message (to a conversation) ───

    public function sendMessage(Request $request, RealtimeService $realtime): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) return response()->json(['error' => 'Invalid token'], 401);

        $request->validate([
            'body' => 'required|string|max:5000',
            'client_id' => 'required|string|max:36',
            'conversation_id' => 'sometimes|integer',
            'socket_id' => 'nullable|string|max:50',
        ]);

        $visitor->update(['last_seen_at' => now()]);

        // Default to admin conversation
        $convId = $request->input('conversation_id');
        if ($convId) {
            $conv = Conversation::findOrFail($convId);
        } else {
            $admin = Admin::first();
            $conv = Conversation::findOrCreateVisitorAdmin($visitor->id, $admin ? $admin->id : 1);
        }

        $message = Message::firstOrCreate(
            ['conversation_id' => $conv->id, 'client_id' => $request->input('client_id')],
            [
                'sender_id' => $visitor->id,
                'sender_type' => 'visitor',
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
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) return response()->json(['error' => 'Invalid token'], 401);

        $request->validate([
            'socket_id' => 'required|string|max:50',
            'channel_name' => 'required|string|max:100',
        ]);

        $channelName = (string) $request->input('channel_name');
        $socketId = (string) $request->input('socket_id');
        $presenceUserId = null;
        $presenceUserInfo = null;

        if ($channelName === RealtimeChannels::ADMIN_PRESENCE) {
            $presenceUserId = 'visitor-' . $visitor->id;
            $presenceUserInfo = [
                'role' => 'visitor',
            ];
        } elseif (preg_match('/^presence-conversation\.(\d+)$/', $channelName, $matches)) {
            $conversation = Conversation::find((int) $matches[1]);
            if (!$conversation) {
                return response()->json(['error' => 'Conversation not found'], 404);
            }

            $isParticipant = $conversation->participant_one_id === $visitor->id
                || $conversation->participant_two_id === $visitor->id;

            if (!$isParticipant) {
                return response()->json(['error' => 'Forbidden'], 403);
            }

            $presenceUserId = 'visitor-' . $visitor->id;
            $presenceUserInfo = [
                'role' => 'visitor',
                'name' => $visitor->name ?? $visitor->username ?? 'Visitor #' . $visitor->id,
            ];
        } else {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $visitor->update(['last_seen_at' => now()]);

        try {
            return response()->json(
                $realtime->authorizeChannel($channelName, $socketId, $presenceUserId, $presenceUserInfo)
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Realtime auth failed'], 503);
        }
    }

    // ─── Poll (admin conversation) ───

    public function poll(Request $request): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) return response()->json(['error' => 'Invalid token'], 401);

        $visitor->update(['last_seen_at' => now()]);

        $sinceId = (int) $request->query('since_id', 0);
        $convId = (int) $request->query('conversation_id', 0);

        if ($convId) {
            $conv = Conversation::find($convId);
        } else {
            $admin = Admin::first();
            $conv = Conversation::findOrCreateVisitorAdmin($visitor->id, $admin ? $admin->id : 1);
        }

        if (!$conv) return response()->json(['messages' => []]);

        $messages = $conv->messages()
            ->where('id', '>', $sinceId)
            ->get()
            ->map(fn($m) => $this->formatMessage($m));

        // Admin online check
        $adminOnline = false;
        if (cache('admin_last_poll')) {
            $adminOnline = now()->diffInSeconds(cache('admin_last_poll')) < 30;
        }

        return response()->json([
            'messages' => $messages,
            'admin_online' => $adminOnline,
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) return response()->json(['error' => 'Invalid token'], 401);

        $request->validate([
            'conversation_id' => 'nullable|integer',
        ]);

        $visitor->update(['last_seen_at' => now()]);

        $conversationId = (int) $request->input('conversation_id', 0);
        $otherOnline = cache('admin_last_poll') && now()->diffInSeconds(cache('admin_last_poll')) < 30;

        if ($conversationId > 0) {
            $conversation = Conversation::find($conversationId);

            if ($conversation) {
                $otherId = $conversation->otherParticipantId($visitor->id);

                if ($conversation->type === 'visitor_admin') {
                    $otherOnline = cache('admin_last_poll') && now()->diffInSeconds(cache('admin_last_poll')) < 30;
                } else {
                    $other = Visitor::find($otherId);
                    $otherOnline = $other ? $other->isOnline() : false;
                }
            }
        }

        return response()->json([
            'status' => 'ok',
            'other_online' => (bool) $otherOnline,
        ]);
    }

    // ─── Save Info (name/email from popup) ───

    public function saveInfo(Request $request): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) return response()->json(['error' => 'Invalid token'], 401);

        $request->validate([
            'name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:191',
        ]);

        $updates = [];
        if ($request->has('name')) $updates['name'] = $request->name;
        if ($request->has('email')) $updates['email'] = $request->email;
        if (!empty($updates)) $visitor->update($updates);

        return response()->json(['status' => 'saved']);
    }

    // ─── Contacts (logged-in visitors see admin + their V2V chats) ───

    public function contacts(Request $request): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) return response()->json(['error' => 'Invalid token'], 401);

        $conversations = Conversation::where('participant_one_id', $visitor->id)
            ->orWhere('participant_two_id', $visitor->id)
            ->with('latestMessage')
            ->get()
            ->map(function ($conv) use ($visitor) {
                $otherId = $conv->otherParticipantId($visitor->id);

                if ($conv->type === 'visitor_admin') {
                    $admin = Admin::find($otherId);
                    $other = [
                        'id' => $admin?->id,
                        'name' => $admin?->name ?? 'Admin',
                        'username' => 'niraj',
                        'is_online' => cache('admin_last_poll') && now()->diffInSeconds(cache('admin_last_poll')) < 30,
                        'type' => 'admin',
                    ];
                } else {
                    $otherVisitor = Visitor::find($otherId);
                    $other = $otherVisitor ? [
                        'id' => $otherVisitor->id,
                        'name' => $otherVisitor->name ?? $otherVisitor->username ?? 'Visitor #' . $otherVisitor->id,
                        'username' => $otherVisitor->username,
                        'is_online' => $otherVisitor->isOnline(),
                        'type' => 'visitor',
                    ] : null;
                }

                $latest = $conv->latestMessage;

                return [
                    'conversation_id' => $conv->id,
                    'type' => $conv->type,
                    'other' => $other,
                    'last_message' => $latest ? [
                        'body' => Str::limit($latest->body, 50),
                        'time' => $latest->created_at->format('g:i A'),
                        'sender_type' => $latest->sender_type,
                    ] : null,
                    'unread_count' => $conv->unreadCountFor($visitor->id, 'visitor'),
                ];
            });

        return response()->json(['contacts' => $conversations]);
    }

    // ─── Search users by username ───

    public function searchUsers(Request $request): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) return response()->json(['error' => 'Invalid token'], 401);

        $q = $request->query('q', '');
        if (strlen($q) < 2) return response()->json(['users' => []]);

        $users = Visitor::where('username', 'like', "%{$q}%")
            ->where('id', '!=', $visitor->id)
            ->whereNotNull('username')
            ->limit(10)
            ->get()
            ->map(fn($v) => [
                'id' => $v->id,
                'username' => $v->username,
                'name' => $v->name ?? $v->username,
                'is_online' => $v->isOnline(),
            ]);

        return response()->json(['users' => $users]);
    }

    // ─── Start conversation with another visitor ───

    public function startChat(Request $request): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) return response()->json(['error' => 'Invalid token'], 401);

        $request->validate([
            'username' => 'required|string',
        ]);

        $other = Visitor::where('username', $request->username)->first();
        if (!$other) return response()->json(['error' => 'User not found'], 404);
        if ($other->id === $visitor->id) return response()->json(['error' => 'Cannot chat with yourself'], 422);

        $conv = Conversation::findOrCreateVisitorVisitor($visitor->id, $other->id);

        return response()->json([
            'conversation_id' => $conv->id,
            'other' => [
                'id' => $other->id,
                'username' => $other->username,
                'name' => $other->name ?? $other->username,
                'is_online' => $other->isOnline(),
            ],
        ]);
    }

    // ─── Get conversation messages (V2V) ───

    public function conversationMessages(Request $request, int $conversationId): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) return response()->json(['error' => 'Invalid token'], 401);

        $visitor->update(['last_seen_at' => now()]);

        $conv = Conversation::findOrFail($conversationId);
        $isParticipant = $conv->participant_one_id === $visitor->id
            || $conv->participant_two_id === $visitor->id;

        if (!$isParticipant) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $this->markConversationReadForVisitor($conv, $visitor);

        $messages = $conv->messages()->get()->map(fn($m) => $this->formatMessage($m));

        // Get other participant info
        $otherId = $conv->otherParticipantId($visitor->id);
        if ($conv->type === 'visitor_admin') {
            $admin = Admin::find($otherId);
            $other = [
                'id' => $admin?->id,
                'name' => $admin?->name ?? 'Admin',
                'username' => 'niraj',
                'is_online' => cache('admin_last_poll') && now()->diffInSeconds(cache('admin_last_poll')) < 30,
                'type' => 'admin',
            ];
        } else {
            $otherVisitor = Visitor::find($otherId);
            $other = $otherVisitor ? [
                'id' => $otherVisitor->id,
                'name' => $otherVisitor->name ?? $otherVisitor->username,
                'username' => $otherVisitor->username,
                'is_online' => $otherVisitor->isOnline(),
                'type' => 'visitor',
            ] : null;
        }

        return response()->json([
            'messages' => $messages,
            'conversation_id' => $conv->id,
            'other' => $other,
        ]);
    }

    // ─── Poll a specific conversation ───

    public function markConversationRead(Request $request, int $conversationId): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) return response()->json(['error' => 'Invalid token'], 401);

        $visitor->update(['last_seen_at' => now()]);

        $conversation = Conversation::findOrFail($conversationId);
        $isParticipant = $conversation->participant_one_id === $visitor->id
            || $conversation->participant_two_id === $visitor->id;

        if (!$isParticipant) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $this->markConversationReadForVisitor($conversation, $visitor);

        return response()->json(['status' => 'ok']);
    }

    public function pollConversation(Request $request, int $conversationId): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) return response()->json(['error' => 'Invalid token'], 401);

        $visitor->update(['last_seen_at' => now()]);

        $sinceId = (int) $request->query('since_id', 0);
        $conv = Conversation::findOrFail($conversationId);

        $messages = $conv->messages()
            ->where('id', '>', $sinceId)
            ->get()
            ->map(fn($m) => $this->formatMessage($m));

        // Other participant online status
        $otherId = $conv->otherParticipantId($visitor->id);
        if ($conv->type === 'visitor_admin') {
            $otherOnline = cache('admin_last_poll') && now()->diffInSeconds(cache('admin_last_poll')) < 30;
        } else {
            $other = Visitor::find($otherId);
            $otherOnline = $other ? $other->isOnline() : false;
        }

        return response()->json([
            'messages' => $messages,
            'other_online' => $otherOnline,
        ]);
    }
}
