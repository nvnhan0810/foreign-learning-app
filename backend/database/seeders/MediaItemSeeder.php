<?php

namespace Database\Seeders;

use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MediaItemSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $sampleTranscript = <<<'TXT'
Hello and welcome to this short listening practice.
Today we are going to talk about daily routines.
I usually wake up at seven o'clock in the morning.
After that, I make a cup of coffee and read the news.
Listening to English every day is the fastest way to improve.
Thanks for listening, and see you in the next lesson!
TXT;

        // YouTube media WITH a transcript (test open/close + edit).
        MediaItem::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'source_id' => 'dQw4w9WgXcQ',
            ],
            [
                'title' => 'Daily Routines — Listening Practice',
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'type' => MediaItem::TYPE_YOUTUBE,
                'frequency' => 'daily',
                'language' => 'en',
                'transcript' => $sampleTranscript,
                'analysis_status' => MediaItem::ANALYSIS_READY,
                'analyzed_at' => now(),
                'question_bank_status' => MediaItem::QUESTION_BANK_PENDING,
                'question_bank_count' => 0,
                'is_active' => true,
                'next_listen_at' => now(),
            ],
        );

        // YouTube media WITHOUT a transcript (test "Thêm transcript" flow).
        MediaItem::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'source_id' => 'M7lc1UVf-VE',
            ],
            [
                'title' => 'News Report — No Transcript Yet',
                'url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE',
                'type' => MediaItem::TYPE_YOUTUBE,
                'frequency' => 'weekly',
                'language' => 'en',
                'transcript' => null,
                'analysis_status' => MediaItem::ANALYSIS_PENDING,
                'question_bank_status' => MediaItem::QUESTION_BANK_PENDING,
                'question_bank_count' => 0,
                'is_active' => true,
                'next_listen_at' => now()->addDay(),
            ],
        );

        // Audio (mp3) media with a transcript.
        MediaItem::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'url' => 'https://example.com/audio/sample-lesson.mp3',
            ],
            [
                'title' => 'Podcast Snippet — Audio Lesson',
                'source_id' => null,
                'type' => MediaItem::TYPE_MP3,
                'frequency' => 'monthly',
                'language' => 'en',
                'transcript' => $sampleTranscript,
                'analysis_status' => MediaItem::ANALYSIS_READY,
                'analyzed_at' => now(),
                'question_bank_status' => MediaItem::QUESTION_BANK_PENDING,
                'question_bank_count' => 0,
                'is_active' => true,
                'next_listen_at' => now()->addDays(3),
            ],
        );
    }
}
