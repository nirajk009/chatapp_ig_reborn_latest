<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_ai_memories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->longText('summary')->nullable();
            $table->unsignedBigInteger('summarized_through_message_id')->default(0);
            $table->unsignedInteger('summary_message_count')->default(0);
            $table->timestamps();

            $table->unique('conversation_id');
            $table->index('conversation_id');
            $table->index('summarized_through_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_ai_memories');
    }
};
