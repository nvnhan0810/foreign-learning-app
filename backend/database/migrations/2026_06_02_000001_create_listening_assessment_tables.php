<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->string('source_id')->nullable()->after('url');
            $table->string('audio_path')->nullable()->after('source_id');
            $table->string('audio_disk', 20)->default('local')->after('audio_path');
            $table->unsignedInteger('duration_seconds')->nullable()->after('audio_disk');
            $table->string('language', 10)->default('en')->after('duration_seconds');
            $table->string('analysis_status', 20)->default('pending')->after('language');
            $table->text('analysis_error')->nullable()->after('analysis_status');
            $table->longText('transcript')->nullable()->after('analysis_error');
            $table->json('analysis_payload')->nullable()->after('transcript');
            $table->timestamp('analyzed_at')->nullable()->after('analysis_payload');
        });

        Schema::create('listening_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // quiz | test | exam
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('question_count')->default(0);
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->string('status', 20)->default('ready'); // generating | ready | failed
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['media_item_id', 'type']);
        });

        Schema::create('listening_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listening_assessment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('order')->default(0);
            $table->string('question_type', 40); // mcq | fill_blank | true_false | comprehension
            $table->text('prompt');
            $table->json('options')->nullable();
            $table->text('correct_answer');
            $table->text('explanation')->nullable();
            $table->unsignedInteger('audio_start_seconds')->nullable();
            $table->unsignedInteger('audio_end_seconds')->nullable();
            $table->timestamps();
        });

        Schema::create('listening_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listening_assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('score')->default(0);
            $table->unsignedSmallInteger('total')->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->json('answers');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listening_attempts');
        Schema::dropIfExists('listening_questions');
        Schema::dropIfExists('listening_assessments');

        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn([
                'source_id',
                'audio_path',
                'audio_disk',
                'duration_seconds',
                'language',
                'analysis_status',
                'analysis_error',
                'transcript',
                'analysis_payload',
                'analyzed_at',
            ]);
        });
    }
};
