<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->string('question_bank_status', 20)->default('pending')->after('analyzed_at');
            $table->unsignedSmallInteger('question_bank_count')->default(0)->after('question_bank_status');
        });

        Schema::table('listening_questions', function (Blueprint $table) {
            $table->foreignId('media_item_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        if (Schema::hasColumn('listening_questions', 'listening_assessment_id')) {
            $questionRows = DB::table('listening_questions')
                ->whereNotNull('listening_assessment_id')
                ->get(['id', 'listening_assessment_id']);

            foreach ($questionRows as $row) {
                $mediaItemId = DB::table('listening_assessments')
                    ->where('id', $row->listening_assessment_id)
                    ->value('media_item_id');

                if ($mediaItemId) {
                    DB::table('listening_questions')
                        ->where('id', $row->id)
                        ->update(['media_item_id' => $mediaItemId]);
                }
            }

            Schema::table('listening_questions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('listening_assessment_id');
            });
        }

        Schema::table('listening_assessments', function (Blueprint $table) {
            $table->dropUnique(['media_item_id', 'type']);
            $table->json('question_ids')->nullable()->after('question_count');
        });

        Schema::table('listening_attempts', function (Blueprint $table) {
            $table->foreignId('media_item_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->nullable()->after('media_item_id');
        });

        if (Schema::hasColumn('listening_attempts', 'listening_assessment_id')) {
            $attemptRows = DB::table('listening_attempts')
                ->whereNotNull('listening_assessment_id')
                ->get(['id', 'listening_assessment_id']);

            foreach ($attemptRows as $row) {
                $assessment = DB::table('listening_assessments')
                    ->where('id', $row->listening_assessment_id)
                    ->first(['media_item_id', 'type']);

                if ($assessment) {
                    DB::table('listening_attempts')
                        ->where('id', $row->id)
                        ->update([
                            'media_item_id' => $assessment->media_item_id,
                            'type' => $assessment->type,
                        ]);
                }
            }
        }

        DB::table('listening_attempts')->delete();
        DB::table('listening_assessments')->delete();

        DB::table('media_items')
            ->whereNotNull('analysis_payload')
            ->update(['question_bank_status' => 'pending']);
    }

    public function down(): void
    {
        Schema::table('listening_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('media_item_id');
            $table->dropColumn('type');
        });

        Schema::table('listening_assessments', function (Blueprint $table) {
            $table->dropColumn('question_ids');
            $table->unique(['media_item_id', 'type']);
        });

        Schema::table('listening_questions', function (Blueprint $table) {
            $table->foreignId('listening_assessment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->dropConstrainedForeignId('media_item_id');
        });

        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn(['question_bank_status', 'question_bank_count']);
        });
    }
};
