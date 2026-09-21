<?php

use Flc\Shared\Support\Text;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            $table->unsignedBigInteger('dictionary_entry_id')->nullable()->after('user_id');
        });

        $rows = DB::table('vocabularies')->select('id', 'word')->get();
        foreach ($rows as $row) {
            $word = Text::lower(trim((string) $row->word));
            $entryId = DB::table('dictionary_entries')->where('word', $word)->value('id');
            if ($entryId === null) {
                $fallbackWord = $word !== '' ? $word : 'unknown-'.$row->id;
                $entryId = DB::table('dictionary_entries')->insertGetId([
                    'word' => $fallbackWord,
                    'phonetic' => null,
                    'audio_url' => null,
                    'source' => 'user_save',
                    'is_curated' => false,
                    'save_count' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('dictionary_meanings')->insert([
                    'dictionary_entry_id' => $entryId,
                    'part_of_speech' => null,
                    'definition' => '(imported from user vocabulary)',
                    'position' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('vocabularies')->where('id', $row->id)->update([
                'dictionary_entry_id' => $entryId,
            ]);
        }

        $missing = DB::table('vocabularies')->whereNull('dictionary_entry_id')->count();
        if ($missing > 0) {
            throw new \RuntimeException("Failed to backfill dictionary_entry_id for {$missing} vocabularies.");
        }

        $dupes = DB::table('vocabularies')
            ->select('user_id', 'dictionary_entry_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_id', 'dictionary_entry_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $dupe) {
            DB::table('vocabularies')
                ->where('user_id', $dupe->user_id)
                ->where('dictionary_entry_id', $dupe->dictionary_entry_id)
                ->where('id', '!=', $dupe->keep_id)
                ->delete();
        }

        Schema::table('vocabularies', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'word']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE vocabularies ALTER COLUMN dictionary_entry_id SET NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE vocabularies MODIFY dictionary_entry_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('vocabularies', function (Blueprint $table) {
            $table->foreign('dictionary_entry_id')
                ->references('id')
                ->on('dictionary_entries')
                ->restrictOnDelete();
            $table->unique(['user_id', 'dictionary_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'dictionary_entry_id']);
            $table->dropForeign(['dictionary_entry_id']);
            $table->dropColumn('dictionary_entry_id');
            $table->unique(['user_id', 'word']);
        });
    }
};
