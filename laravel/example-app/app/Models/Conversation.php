<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'participant_one_id',
        'participant_two_id',
    ];

    public function messages()
    {
        return $this->hasMany(Message::class)->orderBy('created_at', 'asc');
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function unreadCountFor(int $userId, string $userType): int
    {
        return $this->messages()
            ->where('is_read', false)
            ->where(function ($q) use ($userId, $userType) {
                $q->where('sender_id', '!=', $userId)
                  ->orWhere('sender_type', '!=', $userType);
            })
            ->count();
    }

    /**
     * Get or create a visitor↔admin conversation.
     */
    public static function findOrCreateVisitorAdmin(int $visitorId, int $adminId = 1): self
    {
        return static::firstOrCreate([
            'type' => 'visitor_admin',
            'participant_one_id' => $visitorId,
            'participant_two_id' => $adminId,
        ]);
    }

    /**
     * Get or create a visitor↔visitor conversation (always store smaller ID first).
     */
    public static function findOrCreateVisitorVisitor(int $visitorA, int $visitorB): self
    {
        $one = min($visitorA, $visitorB);
        $two = max($visitorA, $visitorB);

        return static::firstOrCreate([
            'type' => 'visitor_visitor',
            'participant_one_id' => $one,
            'participant_two_id' => $two,
        ]);
    }

    /**
     * Get the other participant for a given visitor.
     */
    public function otherParticipantId(int $myId): int
    {
        return $this->participant_one_id === $myId
            ? $this->participant_two_id
            : $this->participant_one_id;
    }
}
