<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationAiMemory extends Model
{
    protected $fillable = [
        'conversation_id',
        'summary',
        'summarized_through_message_id',
        'summary_message_count',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
