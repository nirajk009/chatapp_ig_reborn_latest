<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    protected $fillable = [
        'token',
        'username',
        'name',
        'email',
        'password',
        'ip_address',
        'user_agent',
        'last_seen_at',
    ];

    protected $hidden = [
        'password',
        'token',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subSeconds(30));
    }

    /**
     * Get all conversations this visitor is part of.
     */
    public function conversations()
    {
        return Conversation::where('participant_one_id', $this->id)
            ->orWhere('participant_two_id', $this->id);
    }

    public function profile(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name ?? $this->username ?? 'Visitor #' . $this->id,
            'email' => $this->email,
            'is_online' => $this->isOnline(),
        ];
    }
}
