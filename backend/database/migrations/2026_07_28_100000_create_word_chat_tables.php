<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_chat_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('cursor_agent_id', 80);
            $table->string('status', 20)->default('active');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('word_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->string('cursor_run_id', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('word_chat_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('word_chat_agent_id')->constrained()->cascadeOnDelete();
            $table->string('cursor_agent_id', 80);
            $table->string('cursor_run_id', 80)->unique();
            $table->foreignId('user_message_id')->constrained('word_chat_messages')->cascadeOnDelete();
            $table->foreignId('assistant_message_id')->nullable()->constrained('word_chat_messages')->nullOnDelete();
            $table->string('status', 20)->default('streaming');
            $table->text('assistant_content')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'cursor_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_chat_runs');
        Schema::dropIfExists('word_chat_messages');
        Schema::dropIfExists('word_chat_agents');
    }
};
