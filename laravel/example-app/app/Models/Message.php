<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'visitor_id',
        'sender_type',
        'body',
        'is_read',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
        ];
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function isFromVisitor(): bool
    {
        return $this->sender_type === 'visitor';
    }

    public function isFromAdmin(): bool
    {
        return $this->sender_type === 'admin';
    }
}
