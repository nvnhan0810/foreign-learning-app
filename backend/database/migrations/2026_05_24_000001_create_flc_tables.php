<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('vocabularies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('word');
            $table->string('phonetic')->nullable();
            $table->json('meanings');
            $table->unsignedInteger('times_quizzed')->default(0);
            $table->timestamp('last_quizzed_at')->nullable();
            $table->timestamp('last_correct_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'word']);
        });

        Schema::create('vocabulary_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_id')->constrained()->cascadeOnDelete();
            $table->text('example');
            $table->string('definition_ref')->nullable();
            $table->timestamps();
        });

        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('url');
            $table->string('type', 20); // audio | youtube
            $table->string('frequency', 20); // daily | weekly | monthly
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_listen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('correct');
            $table->string('question_type', 40);
            $table->timestamps();
        });

        Schema::create('listen_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('listened_at');
            $table->timestamps();
        });

        Schema::create('dictionary_cache', function (Blueprint $table) {
            $table->string('word')->primary();
            $table->json('payload');
            $table->timestamp('cached_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_cache');
        Schema::dropIfExists('listen_logs');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('media_items');
        Schema::dropIfExists('vocabulary_examples');
        Schema::dropIfExists('vocabularies');
        Schema::dropIfExists('personal_access_tokens');
    }
};
