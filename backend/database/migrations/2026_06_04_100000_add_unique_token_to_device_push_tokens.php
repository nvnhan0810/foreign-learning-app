<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateTokens = DB::table('device_push_tokens')
            ->select('token')
            ->groupBy('token')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('token');

        foreach ($duplicateTokens as $token) {
            $keepId = DB::table('device_push_tokens')
                ->where('token', $token)
                ->orderByDesc('updated_at')
                ->value('id');

            DB::table('device_push_tokens')
                ->where('token', $token)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        Schema::table('device_push_tokens', function (Blueprint $table) {
            $table->unique('token');
        });
    }

    public function down(): void
    {
        Schema::table('device_push_tokens', function (Blueprint $table) {
            $table->dropUnique(['token']);
        });
    }
};
