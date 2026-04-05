<?php

namespace App\Services;

use App\Models\Conversation;
use App\Support\RealtimeChannels;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

class RealtimeService
{
    private ?Pusher $client = null;

    public function enabled(): bool
    {
        return filled(config('services.pusher.key'))
            && filled(config('services.pusher.secret'))
            && filled(config('services.pusher.app_id'))
            && filled(config('services.pusher.cluster'));
    }

    public function authorizeChannel(
        string $channelName,
        string $socketId,
        ?string $userId = null,
        ?array $userInfo = null
    ): array
    {
        $client = $this->client();

        if (!$client) {
            throw new \RuntimeException('Pusher is not configured.');
        }

        $payload = str_starts_with($channelName, 'presence-')
            ? $client->authorizePresenceChannel($channelName, $socketId, $userId ?? '', $userInfo ?? [])
            : $client->authorizeChannel($channelName, $socketId);

        return json_decode(
            $payload,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function publishMessage(Conversation $conversation, array $payload, ?string $socketId = null): void
    {
        $channels = [RealtimeChannels::conversation($conversation)];

        if ($conversation->type === 'visitor_admin') {
            $channels[] = RealtimeChannels::ADMIN_FEED;
        }

        $this->trigger($conversation, $channels, 'message.created', $payload, $socketId);
    }

    public function publishTyping(Conversation $conversation, array $payload, ?string $socketId = null): void
    {
        $this->trigger(
            $conversation,
            [RealtimeChannels::conversation($conversation)],
            'typing.updated',
            $payload,
            $socketId
        );
    }

    public function publishReadReceipt(Conversation $conversation, array $payload, ?string $socketId = null): void
    {
        $this->trigger(
            $conversation,
            [RealtimeChannels::conversation($conversation)],
            'message.read',
            $payload,
            $socketId
        );
    }

    private function client(): ?Pusher
    {
        if (!$this->enabled()) {
            return null;
        }

        if ($this->client) {
            return $this->client;
        }

        $verifyTls = filter_var(config('services.pusher.verify_tls', true), FILTER_VALIDATE_BOOL);
        $httpClient = new Client([
            'verify' => $verifyTls,
        ]);

        $this->client = new Pusher(
            (string) config('services.pusher.key'),
            (string) config('services.pusher.secret'),
            (string) config('services.pusher.app_id'),
            [
                'cluster' => (string) config('services.pusher.cluster'),
                'useTLS' => true,
            ],
            $httpClient
        );

        return $this->client;
    }

    private function trigger(
        Conversation $conversation,
        array $channels,
        string $eventName,
        array $payload,
        ?string $socketId = null
    ): void {
        $client = $this->client();

        if (!$client) {
            return;
        }

        $params = [];
        if ($socketId) {
            $params['socket_id'] = $socketId;
        }

        try {
            $client->trigger(array_values(array_unique($channels)), $eventName, $payload, $params);
        } catch (\Throwable $e) {
            Log::warning('Realtime publish failed', [
                'conversation_id' => $conversation->id,
                'event' => $eventName,
                'channel_count' => count($channels),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
