<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Visitor extends Model
{
    protected $fillable = [
        'token',
        'name',
        'email',
        'ip_address',
        'user_agent',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public static function createWithToken(string $ip, ?string $userAgent): static
    {
        return static::create([
            'token' => Str::random(64),
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'last_seen_at' => now(),
        ]);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->diffInSeconds(now()) < 30;
    }
}
