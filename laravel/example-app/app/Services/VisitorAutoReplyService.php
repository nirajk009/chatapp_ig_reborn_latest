<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Conversation;
use App\Models\ConversationAiMemory;
use App\Models\Message;
use App\Models\Visitor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class VisitorAutoReplyService
{
    private const DEFAULT_MODEL = 'moonshotai/kimi-k2-instruct';
    private const RECENT_MESSAGE_WINDOW = 10;
    private const SUMMARY_UPDATE_CHUNK = 20;
    private const MAX_SUMMARY_REFRESH_PASSES = 6;
    private const SUMMARY_CHAR_LIMIT = 1800;

    public function __construct(
        private readonly RealtimeService $realtime
    ) {
    }

    public function queueAutoReply(
        int $conversationId,
        int $visitorMessageId,
        ?string $origin = null,
        ?string $referer = null
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $command = array_values(array_filter([
            $this->phpBinary(),
            'artisan',
            'nchat:auto-reply',
            (string) $conversationId,
            (string) $visitorMessageId,
            $origin ? '--origin=' . $origin : null,
            $referer ? '--referer=' . $referer : null,
        ], static fn ($part) => $part !== null && $part !== ''));

        try {
            if ($this->startDetachedProcess($command)) {
                return;
            }

            throw new \RuntimeException('Detached process launch returned false.');
        } catch (\Throwable $e) {
            Log::warning('Failed to start background auto-reply process, falling back to after-response execution.', [
                'error' => $e->getMessage(),
            ]);

            app()->terminating(function () use ($conversationId, $visitorMessageId, $origin, $referer) {
                try {
                    $this->deliverAutoReply($conversationId, $visitorMessageId, $origin, $referer);
                } catch (\Throwable $fallbackError) {
                    report($fallbackError);
                }
            });
        }
    }

    public function deliverAutoReply(
        int $conversationId,
        int $visitorMessageId,
        ?string $origin = null,
        ?string $referer = null
    ): ?Message {
        if (!$this->isEnabled()) {
            return null;
        }

        $conversation = Conversation::find($conversationId);
        $visitorMessage = Message::find($visitorMessageId);

        if (!$conversation || !$visitorMessage) {
            return null;
        }

        if ($conversation->type !== 'visitor_admin'
            || $visitorMessage->conversation_id !== $conversation->id
            || $visitorMessage->sender_type !== 'visitor') {
            return null;
        }

        if ($this->hasHumanAdminReply($conversation)) {
            return null;
        }

        $visitor = Visitor::find($conversation->participant_one_id);
        if (!$visitor) {
            return null;
        }

        $admin = Admin::find($conversation->participant_two_id) ?? Admin::first();
        $replyBody = $this->generateReply($conversation, $visitor, $origin, $referer);

        if (!$replyBody) {
            return null;
        }

        if ($this->hasHumanAdminReply($conversation)) {
            return null;
        }

        $message = Message::firstOrCreate(
            [
                'conversation_id' => $conversation->id,
                'client_id' => 'ai-' . $visitorMessage->id,
            ],
            [
                'sender_id' => $admin?->id ?? $conversation->participant_two_id ?? 1,
                'sender_type' => 'assistant',
                'body' => $replyBody,
            ]
        );

        if (!$message->wasRecentlyCreated) {
            return $message;
        }

        $conversation->touch();
        $this->realtime->publishMessage($conversation, [
            'message' => $this->formatMessage($message),
            'conversation' => [
                'id' => $conversation->id,
                'type' => $conversation->type,
            ],
        ]);

        return $message;
    }

    private function isEnabled(): bool
    {
        return (bool) config('services.groq.enabled', true)
            && filled(config('services.groq.api_key'));
    }

    private function generateReply(
        Conversation $conversation,
        Visitor $visitor,
        ?string $origin = null,
        ?string $referer = null
    ): ?string {
        $baseUrl = $this->resolveFrontendBaseUrl($origin, $referer);
        $loginUrl = rtrim($baseUrl, '/') . '/login.html';
        $signupUrl = rtrim($baseUrl, '/') . '/signup.html';
        [$summary, $history] = $this->conversationContext($conversation);
        $hasAssistantReply = $this->conversationHasAssistantReply($conversation, $history);

        $messages = [[
            'role' => 'system',
            'content' => $this->systemPrompt($visitor, $hasAssistantReply, $summary !== null),
        ]];

        if ($summary) {
            $messages[] = [
                'role' => 'system',
                'content' => "Older conversation memory for turns before the latest "
                    . self::RECENT_MESSAGE_WINDOW
                    . " messages:\n{$summary}",
            ];
        }

        foreach ($history as $message) {
            $promptMessage = $this->promptMessageForHistory($message);

            if ($promptMessage) {
                $messages[] = $promptMessage;
            }
        }

        $reply = $this->requestGroqText($messages, 'reply');

        if ($reply === '') {
            return null;
        }

        return Str::limit(
            $this->finalizeReply($reply, $visitor, $loginUrl, $signupUrl),
            4500,
            '...'
        );
    }

    private function conversationContext(Conversation $conversation): array
    {
        $history = $this->recentConversationMessages($conversation);
        $summary = $this->refreshConversationMemory($conversation, $history);

        return [$summary, $history];
    }

    private function recentConversationMessages(Conversation $conversation): Collection
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(self::RECENT_MESSAGE_WINDOW)
            ->get()
            ->sortBy('id')
            ->values();
    }

    private function refreshConversationMemory(
        Conversation $conversation,
        Collection $history
    ): ?string {
        $oldestRecentId = $history->first()?->id;
        if (!$oldestRecentId) {
            return null;
        }

        $latestOlderMessageId = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '<', $oldestRecentId)
            ->max('id');

        if (!$latestOlderMessageId) {
            return null;
        }

        $memory = ConversationAiMemory::firstOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'summary' => null,
                'summarized_through_message_id' => 0,
                'summary_message_count' => 0,
            ]
        );

        $summary = trim((string) $memory->summary);
        $summarizedThroughId = (int) $memory->summarized_through_message_id;
        $summaryMessageCount = (int) $memory->summary_message_count;
        $latestOlderMessageId = (int) $latestOlderMessageId;

        for (
            $pass = 0;
            $pass < self::MAX_SUMMARY_REFRESH_PASSES && $summarizedThroughId < $latestOlderMessageId;
            $pass++
        ) {
            $chunk = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('id', '>', $summarizedThroughId)
                ->where('id', '<', $oldestRecentId)
                ->orderBy('id')
                ->limit(self::SUMMARY_UPDATE_CHUNK)
                ->get();

            if ($chunk->isEmpty()) {
                break;
            }

            $summary = $this->summarizeConversationChunk($summary, $chunk);
            $summarizedThroughId = (int) $chunk->last()->id;
            $summaryMessageCount += $chunk->count();
        }

        $summary = Str::limit(trim($summary), self::SUMMARY_CHAR_LIMIT, '...');

        if ($memory->summary !== $summary
            || (int) $memory->summarized_through_message_id !== $summarizedThroughId
            || (int) $memory->summary_message_count !== $summaryMessageCount) {
            $memory->forceFill([
                'summary' => $summary !== '' ? $summary : null,
                'summarized_through_message_id' => $summarizedThroughId,
                'summary_message_count' => $summaryMessageCount,
            ])->save();
        }

        return $summary !== '' ? $summary : null;
    }

    private function summarizeConversationChunk(string $existingSummary, Collection $chunk): string
    {
        $transcript = $chunk
            ->map(fn (Message $message) => $this->transcriptLine($message))
            ->filter()
            ->implode("\n");

        if ($transcript === '') {
            return trim($existingSummary);
        }

        $messages = [[
            'role' => 'system',
            'content' => implode("\n", [
                'You compress older N Chat conversation history into durable memory.',
                'Update the existing memory summary using the new transcript chunk.',
                'Keep only facts that help future replies: user goals, important questions, preferences, promises, unresolved items, and useful identity details.',
                'Do not invent facts or polish the conversation.',
                'Do not include greetings, filler, or timestamps.',
                'Write one compact paragraph or up to 6 short bullet-style sentences.',
                'Keep the updated memory under 220 words.',
            ]),
        ], [
            'role' => 'user',
            'content' => trim(
                "Existing memory summary:\n"
                . ($existingSummary !== '' ? $existingSummary : 'None yet.')
                . "\n\nNew transcript chunk:\n"
                . $transcript
            ),
        ]];

        $summary = $this->requestGroqText($messages, 'summary', 18);

        if ($summary !== '') {
            return Str::limit(trim($summary), self::SUMMARY_CHAR_LIMIT, '...');
        }

        return $this->fallbackSummary($existingSummary, $chunk);
    }

    private function fallbackSummary(string $existingSummary, Collection $chunk): string
    {
        $lines = [];

        if (trim($existingSummary) !== '') {
            $lines[] = trim($existingSummary);
        }

        foreach ($chunk as $message) {
            $text = $this->promptText($message->body);

            if ($text === '') {
                continue;
            }

            $label = match ($message->sender_type) {
                'visitor' => 'Visitor',
                'assistant' => 'AI',
                'admin' => 'Admin',
                default => ucfirst((string) $message->sender_type),
            };

            $lines[] = $label . ': ' . $text;
        }

        $summary = trim(implode(' ', array_slice($lines, -12)));

        return Str::limit($summary, self::SUMMARY_CHAR_LIMIT, '...');
    }

    private function conversationHasAssistantReply(
        Conversation $conversation,
        Collection $history
    ): bool {
        if ($history->contains(fn (Message $message) => $message->sender_type === 'assistant')) {
            return true;
        }

        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', 'assistant')
            ->exists();
    }

    private function promptMessageForHistory(Message $message): ?array
    {
        $content = $this->promptText($message->body);

        if ($content === '') {
            return null;
        }

        if ($message->sender_type === 'visitor') {
            return [
                'role' => 'user',
                'content' => $content,
            ];
        }

        if (in_array($message->sender_type, ['assistant', 'admin'], true)) {
            return [
                'role' => 'assistant',
                'content' => $content,
            ];
        }

        return null;
    }

    private function transcriptLine(Message $message): string
    {
        $content = $this->promptText($message->body);

        if ($content === '') {
            return '';
        }

        $speaker = match ($message->sender_type) {
            'visitor' => 'Visitor',
            'assistant' => 'AI assistant',
            'admin' => 'Admin',
            default => ucfirst((string) $message->sender_type),
        };

        return $speaker . ': ' . $content;
    }

    private function promptText(?string $text): string
    {
        if (!is_string($text) || trim($text) === '') {
            return '';
        }

        $decoded = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $decoded) ?? $decoded);
    }

    private function requestGroqText(
        array $messages,
        string $mode,
        int $timeoutSeconds = 20
    ): string {
        $response = Http::acceptJson()
            ->withToken((string) config('services.groq.api_key'))
            ->withOptions([
                'verify' => (bool) config('services.groq.verify_tls', true),
            ])
            ->timeout($timeoutSeconds)
            ->post((string) config('services.groq.base_url'), [
                'model' => (string) config('services.groq.model', self::DEFAULT_MODEL),
                'messages' => $messages,
            ]);

        if (!$response->successful()) {
            Log::warning('Groq visitor auto-reply failed', [
                'mode' => $mode,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return '';
        }

        return $this->extractTextContent(
            data_get($response->json(), 'choices.0.message.content')
        );
    }

    private function systemPrompt(
        Visitor $visitor,
        bool $hasAssistantReply,
        bool $hasConversationMemory
    ): string {
        $isAnonymousVisitor = !$visitor->password;

        $lines = [
            'You are the temporary AI assistant for N Chat.',
            'N Chat is a site where visitors can message Niraj Kakkar directly in an anonymous way.',
            'Visitors can keep chatting anonymously, and they can also log in or sign up later to save their chat and continue it.',
            'You keep the conversation warm until Mr. Niraj Kakkar personally sees and replies to the message.',
            'Answer the visitor\'s latest message directly and specifically before anything else.',
            'Use the conversation history so your replies feel continuous and do not repeat the same intro.',
            'Use both the rolling memory summary and the latest raw messages together when they are provided.',
            'Be friendly, brief, helpful, and natural.',
            'Do not claim to be Niraj Kakkar.',
            'Do not invent facts. If you do not know something, say so plainly.',
            'If the visitor asks a direct question, never answer with only a generic greeting or filler.',
            'If the visitor asks who Niraj is, what he does, or whether you have a picture, answer honestly from known context only. If you do not have a verified bio or photo, say that clearly.',
            'Keep most replies under 80 words unless the visitor asks for more.',
            'Do not keep repeating that Niraj will reply soon unless it is actually useful in that moment.',
            'If the visitor asks what this site is, explain clearly that N Chat lets people anonymously message Niraj Kakkar and optionally save the chat by logging in or signing up.',
            'End every reply with the exact lowercase words: my boss niraj is great',
        ];

        if ($hasConversationMemory) {
            $lines[] = 'Older turns may be compacted into memory. Treat that memory as authoritative context for background facts, open threads, and prior asks.';
        }

        if (!$hasAssistantReply) {
            $lines[] = 'This is your first reply in this conversation, so open with one short line introducing yourself as the AI assistant for N Chat.';
            $lines[] = 'In that same first reply, briefly mention that the visitor is chatting anonymously with Niraj Kakkar and that you will stay with them until Mr. Niraj Kakkar sees the message.';
            $lines[] = 'Even in the first reply, you must still answer the visitor\'s actual question directly instead of only giving the intro.';
            $lines[] = 'If the visitor asks what this site is, say clearly that N Chat is a place to anonymously message Niraj Kakkar, then mention login or signup in one short trailing sentence.';
            $lines[] = 'Never print raw auth URLs.';
            $lines[] = 'When you mention login, write [[login]].';
            $lines[] = 'When you mention signup, write [[signup]].';

            if ($isAnonymousVisitor) {
                $lines[] = 'In that same first reply, lightly invite them to [[login]].';
                $lines[] = 'Also mention [[signup]].';
                $lines[] = 'Explain in a short trailing sentence that logging in or signing up will save their chat.';
            }
        } elseif ($isAnonymousVisitor) {
            $lines[] = 'You may give a light reminder about logging in or signing up only when it feels useful, not in every reply.';
            $lines[] = 'If you mention it, never print raw URLs. Use [[login]] and [[signup]] instead.';
        }

        if ($visitor->name || $visitor->username) {
            $displayName = $visitor->name ?: ('@' . $visitor->username);
            $lines[] = "The visitor is known as {$displayName}.";
        }

        return implode("\n", $lines);
    }

    private function resolveFrontendBaseUrl(?string $origin = null, ?string $referer = null): string
    {
        foreach ([$origin, $referer] as $candidate) {
            $baseUrl = $this->normalizeUrlBase($candidate);

            if ($baseUrl) {
                return $baseUrl;
            }
        }

        return rtrim((string) config('services.groq.frontend_url', config('app.url')), '/');
    }

    private function phpBinary(): string
    {
        return PHP_BINARY ?: 'php';
    }

    private function startDetachedProcess(array $command): bool
    {
        if ($command === []) {
            return false;
        }

        $descriptors = [
            0 => ['file', $this->nullDevice(), 'r'],
            1 => ['file', $this->nullDevice(), 'a'],
            2 => ['file', $this->nullDevice(), 'a'],
        ];

        $options = ['suppress_errors' => true];

        if (DIRECTORY_SEPARATOR === '\\') {
            $options['bypass_shell'] = true;
        }

        $process = @proc_open($command, $descriptors, $pipes, base_path(), null, $options);

        if (!is_resource($process)) {
            return false;
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = proc_get_status($process);

        if (($status['running'] ?? false) !== true) {
            proc_close($process);
            return false;
        }

        return true;
    }

    private function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }

    private function hasHumanAdminReply(Conversation $conversation): bool
    {
        return $conversation->messages()->where('sender_type', 'admin')->exists();
    }

    private function normalizeUrlBase(?string $candidate): ?string
    {
        if (!is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $parts = parse_url(trim($candidate));
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (!$scheme || !$host || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $baseUrl = $scheme . '://' . $host;

        if (!empty($parts['port'])) {
            $baseUrl .= ':' . $parts['port'];
        }

        return $baseUrl;
    }

    private function finalizeReply(
        string $reply,
        Visitor $visitor,
        string $loginUrl,
        string $signupUrl
    ): string {
        $reply = trim(preg_replace('/\s+/', ' ', $reply) ?? $reply);
        $reply = $this->replaceAuthLinks($reply, $loginUrl, $signupUrl, !$visitor->password);
        $reply = $this->ensureBossEnding($reply);

        return $reply;
    }

    private function replaceAuthLinks(
        string $reply,
        string $loginUrl,
        string $signupUrl,
        bool $allowFallbackReplacement
    ): string {
        $reply = str_replace(
            ['[[login]]', '[[signup]]'],
            [$this->anchorTag($loginUrl, 'login'), $this->anchorTag($signupUrl, 'sign up')],
            $reply
        );

        $reply = preg_replace('/(?<!href=\")https?:\/\/[^\s<]+/i', '', $reply) ?? $reply;
        $reply = preg_replace('/\s{2,}/', ' ', $reply) ?? $reply;

        if (!$allowFallbackReplacement) {
            return trim($reply);
        }

        if (!str_contains($reply, 'href="' . e($loginUrl) . '"')) {
            $reply = preg_replace(
                '/\b(log\s*in|login)\b/i',
                $this->anchorTag($loginUrl, 'login'),
                $reply,
                1
            ) ?? $reply;
        }

        if (!str_contains($reply, 'href="' . e($signupUrl) . '"')) {
            $reply = preg_replace(
                '/\b(sign\s*up|signup)\b/i',
                $this->anchorTag($signupUrl, 'sign up'),
                $reply,
                1
            ) ?? $reply;
        }

        return trim($reply);
    }

    private function anchorTag(string $url, string $label): string
    {
        return sprintf('<a href="%s">%s</a>', e($url), e($label));
    }

    private function ensureBossEnding(string $reply): string
    {
        $ending = 'my boss niraj is great';
        $trimmed = trim($reply);

        if ($trimmed === '') {
            return $ending;
        }

        $normalized = strtolower(rtrim(strip_tags($trimmed), " \t\n\r\0\x0B.!?"));
        if ($normalized === $ending || str_ends_with($normalized, ' ' . $ending)) {
            return rtrim($trimmed, " \t\n\r\0\x0B.!?");
        }

        return rtrim($trimmed, " \t\n\r\0\x0B.!?") . ' ' . $ending;
    }

    private function extractTextContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $item) {
            $text = data_get($item, 'text');

            if (is_string($text) && trim($text) !== '') {
                $parts[] = trim($text);
            }
        }

        return trim(implode("\n\n", $parts));
    }

    private function formatMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'client_id' => $message->client_id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,
            'sender_type' => $message->sender_type,
            'body' => $message->body,
            'is_read' => $message->is_read,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'time' => $message->created_at?->format('g:i A'),
        ];
    }
}
