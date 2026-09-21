<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vocabulary_learning_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vocabulary_id')->nullable()->constrained()->nullOnDelete();
            $table->string('word', 120);
            $table->string('insight_type', 40);
            $table->text('question')->nullable();
            $table->text('content');
            $table->foreignId('source_message_id')->nullable()->constrained('word_chat_messages')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->boolean('quiz_eligible')->default(true);
            $table->unsignedInteger('times_used_in_quiz')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'word']);
            $table->index(['user_id', 'quiz_eligible', 'times_used_in_quiz']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vocabulary_learning_insights');
    }
};
