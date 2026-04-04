<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            // For visitor↔admin chats: visitor_id is set, admin_id is set
            // For visitor↔visitor chats: both are visitor IDs stored as participant_one/two
            $table->string('type')->default('visitor_admin'); // 'visitor_admin' or 'visitor_visitor'
            $table->unsignedBigInteger('participant_one_id'); // visitor_id always
            $table->unsignedBigInteger('participant_two_id'); // admin_id or another visitor_id
            $table->timestamps();

            $table->unique(['type', 'participant_one_id', 'participant_two_id']);
            $table->index('participant_one_id');
            $table->index('participant_two_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
