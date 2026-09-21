<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictionary_entries', function (Blueprint $table) {
            $table->id();
            $table->string('word', 255)->unique();
            $table->string('phonetic', 120)->nullable();
            $table->text('audio_url')->nullable();
            $table->string('source', 40)->default('user_save');
            $table->boolean('is_curated')->default(false);
            $table->unsignedInteger('save_count')->default(0);
            $table->timestamps();
        });

        Schema::create('dictionary_meanings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dictionary_entry_id')->constrained('dictionary_entries')->cascadeOnDelete();
            $table->string('part_of_speech', 40)->nullable();
            $table->text('definition');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['dictionary_entry_id', 'position']);
        });

        Schema::create('dictionary_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dictionary_meaning_id')->constrained('dictionary_meanings')->cascadeOnDelete();
            $table->text('example');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['dictionary_meaning_id', 'position']);
        });

        Schema::create('dictionary_synonyms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dictionary_entry_id')->constrained('dictionary_entries')->cascadeOnDelete();
            $table->foreignId('dictionary_meaning_id')->nullable()->constrained('dictionary_meanings')->cascadeOnDelete();
            $table->string('term', 120);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['dictionary_entry_id', 'dictionary_meaning_id']);
        });

        Schema::create('dictionary_antonyms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dictionary_entry_id')->constrained('dictionary_entries')->cascadeOnDelete();
            $table->foreignId('dictionary_meaning_id')->nullable()->constrained('dictionary_meanings')->cascadeOnDelete();
            $table->string('term', 120);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['dictionary_entry_id', 'dictionary_meaning_id']);
        });

        Schema::dropIfExists('dictionary_cache');
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_antonyms');
        Schema::dropIfExists('dictionary_synonyms');
        Schema::dropIfExists('dictionary_examples');
        Schema::dropIfExists('dictionary_meanings');
        Schema::dropIfExists('dictionary_entries');

        Schema::create('dictionary_cache', function (Blueprint $table) {
            $table->string('word')->primary();
            $table->json('payload');
            $table->timestamp('cached_at');
        });
    }
};
