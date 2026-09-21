<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('event_store');
    }

    public function down(): void
    {
        Schema::create('event_store', function (Blueprint $table) {
            $table->id();
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->unsignedInteger('playhead');
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('recorded_at')->useCurrent();

            $table->unique(['aggregate_type', 'aggregate_id', 'playhead']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }
};
