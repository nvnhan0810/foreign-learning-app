<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            $table->dropColumn(['word', 'phonetic', 'meanings']);
        });

        Schema::dropIfExists('vocabulary_examples');
    }

    public function down(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            $table->string('word')->nullable()->after('dictionary_entry_id');
            $table->string('phonetic')->nullable()->after('word');
            $table->json('meanings')->nullable()->after('phonetic');
        });

        Schema::create('vocabulary_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_id')->constrained('vocabularies')->cascadeOnDelete();
            $table->text('example');
            $table->string('definition_ref')->nullable();
            $table->timestamps();
        });
    }
};
