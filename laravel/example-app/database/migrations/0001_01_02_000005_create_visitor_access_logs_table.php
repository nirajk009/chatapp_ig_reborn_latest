<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visitor_id');
            $table->string('event_type', 40)->default('visit');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['visitor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_access_logs');
    }
};
