<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE listening_questions ALTER COLUMN correct_answer TYPE TEXT');
        } else {
            Schema::table('listening_questions', function ($table) {
                $table->text('correct_answer')->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE listening_questions ALTER COLUMN correct_answer TYPE VARCHAR(255)');
        } else {
            Schema::table('listening_questions', function ($table) {
                $table->string('correct_answer')->change();
            });
        }
    }
};
