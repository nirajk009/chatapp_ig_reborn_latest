<?php

namespace App\Support;

use App\Models\Conversation;

final class RealtimeChannels
{
    public const ADMIN_FEED = 'private-admin.feed';
    public const ADMIN_PRESENCE = 'presence-admin.global';

    public static function conversation(Conversation|int $conversation): string
    {
        $conversationId = $conversation instanceof Conversation
            ? $conversation->id
            : $conversation;

        return 'presence-conversation.' . $conversationId;
    }
}
