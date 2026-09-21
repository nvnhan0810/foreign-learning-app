<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('word_chat_agents', function (Blueprint $table) {
            $table->unsignedSmallInteger('prompt_version')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('word_chat_agents', function (Blueprint $table) {
            $table->dropColumn('prompt_version');
        });
    }
};
