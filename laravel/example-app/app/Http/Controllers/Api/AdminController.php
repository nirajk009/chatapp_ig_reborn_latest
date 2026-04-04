<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Visitor;
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
            'conversation_id' => $msg->conversation_id,
            'sender_id' => $msg->sender_id,
            'sender_type' => $msg->sender_type,
            'body' => $msg->body,
            'is_read' => $msg->is_read,
            'time' => $msg->created_at->format('g:i A'),
        ];
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
        $conv->messages()
            ->where('sender_type', 'visitor')
            ->where('is_read', false)
            ->update(['is_read' => true]);

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

    public function sendMessage(Request $request, int $visitorId): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) return response()->json(['error' => 'Unauthorized'], 401);

        $request->validate([
            'body' => 'required|string|max:5000',
            'client_id' => 'required|string|max:36',
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

        $conv->touch(); // update conversation timestamp

        return response()->json([
            'message' => $this->formatMessage($message),
        ], 201);
    }

    // ─── Poll (global — for conversation list) ───

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
        $conv->messages()
            ->where('sender_type', 'visitor')
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $messages = $conv->messages()
            ->where('id', '>', $sinceId)
            ->get()
            ->map(fn($m) => $this->formatMessage($m));

        return response()->json([
            'messages' => $messages,
            'visitor_online' => $visitor->isOnline(),
        ]);
    }
}
