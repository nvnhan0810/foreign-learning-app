<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('vocabulary_learning_insights');
        Schema::dropIfExists('word_chat_runs');
        Schema::dropIfExists('word_chat_messages');
        Schema::dropIfExists('word_chat_agents');
    }

    public function down(): void
    {
        // Word Chat feature removed — tables are not recreated.
    }
};
