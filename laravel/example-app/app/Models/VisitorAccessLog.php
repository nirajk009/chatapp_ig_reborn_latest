<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorAccessLog extends Model
{
    protected $fillable = [
        'visitor_id',
        'event_type',
        'ip_address',
        'user_agent',
    ];

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }
}
