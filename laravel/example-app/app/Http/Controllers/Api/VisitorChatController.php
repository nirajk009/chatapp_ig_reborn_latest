<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use App\Models\Message;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VisitorChatController extends Controller
{
    /**
     * Initialize a visitor session.
     * Creates a new visitor with a unique token, or returns existing one.
     * POST /api/visitor/init
     */
    public function init(Request $request): JsonResponse
    {
        $token = $request->header('X-Visitor-Token');

        if ($token) {
            $visitor = Visitor::where('token', $token)->first();
            if ($visitor) {
                $visitor->update(['last_seen_at' => now()]);
                $admin = Admin::first();

                return response()->json([
                    'visitor' => [
                        'id' => $visitor->id,
                        'token' => $visitor->token,
                        'name' => $visitor->name,
                        'email' => $visitor->email,
                    ],
                    'admin' => $admin ? $admin->publicProfile() : null,
                ]);
            }
        }

        // Create new visitor
        $visitor = Visitor::createWithToken(
            $request->ip(),
            $request->userAgent()
        );

        $admin = Admin::first();

        return response()->json([
            'visitor' => [
                'id' => $visitor->id,
                'token' => $visitor->token,
                'name' => $visitor->name,
                'email' => $visitor->email,
            ],
            'admin' => $admin ? $admin->publicProfile() : null,
        ], 201);
    }

    /**
     * Get message history for this visitor.
     * GET /api/visitor/messages
     */
    public function getMessages(Request $request): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        $visitor->update(['last_seen_at' => now()]);

        $messages = $visitor->messages()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($m) => $this->formatMessage($m));

        return response()->json(['messages' => $messages]);
    }

    /**
     * Send a message as visitor.
     * POST /api/visitor/messages
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $visitor->update(['last_seen_at' => now()]);

        $message = $visitor->messages()->create([
            'sender_type' => 'visitor',
            'body' => $request->input('body'),
        ]);

        return response()->json([
            'message' => $this->formatMessage($message),
        ], 201);
    }

    /**
     * Poll for new messages since a given message ID.
     * GET /api/visitor/poll?since_id=123
     */
    public function poll(Request $request): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        $visitor->update(['last_seen_at' => now()]);

        $sinceId = (int) $request->query('since_id', 0);

        $messages = $visitor->messages()
            ->where('id', '>', $sinceId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($m) => $this->formatMessage($m));

        // Mark admin messages as read
        if ($messages->isNotEmpty()) {
            $visitor->messages()
                ->where('sender_type', 'admin')
                ->where('is_read', false)
                ->where('id', '<=', $messages->last()['id'])
                ->update(['is_read' => true]);
        }

        return response()->json([
            'messages' => $messages,
            'admin_online' => $this->isAdminOnline(),
        ]);
    }

    /**
     * Save visitor's name and email (the "save chat" prompt).
     * POST /api/visitor/save-info
     */
    public function saveInfo(Request $request): JsonResponse
    {
        $visitor = $this->resolveVisitor($request);
        if (!$visitor) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
        ]);

        $visitor->update(array_filter([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
        ]));

        return response()->json([
            'visitor' => [
                'id' => $visitor->id,
                'token' => $visitor->token,
                'name' => $visitor->name,
                'email' => $visitor->email,
            ],
        ]);
    }

    // ---------- Helpers ----------

    private function resolveVisitor(Request $request): ?Visitor
    {
        $token = $request->header('X-Visitor-Token');
        if (!$token) return null;
        return Visitor::where('token', $token)->first();
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

    private function isAdminOnline(): bool
    {
        // Simple heuristic: admin is "online" if they polled in last 30 seconds
        // We'll track this with cache
        $lastPoll = cache('admin_last_poll');
        return $lastPoll && now()->diffInSeconds($lastPoll) < 30;
    }
}
