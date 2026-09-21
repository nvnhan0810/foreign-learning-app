<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cursor Cloud Agents API (shared: listening analysis + word chat)
    |--------------------------------------------------------------------------
    */

    'cursor_api_key' => env('CURSOR_API_KEY'),

    'cursor_api_base' => env('CURSOR_API_BASE', 'https://api.cursor.com'),

    'cursor_model' => env('CURSOR_MODEL', 'composer-2.5'),

    'cursor_timeout_seconds' => (int) env('CURSOR_TIMEOUT_SECONDS', 180),

    'cursor_poll_interval_seconds' => (int) env('CURSOR_POLL_INTERVAL_SECONDS', 3),

    /** Per-request HTTP timeout when talking to Cursor (create/poll). */
    'cursor_http_timeout_seconds' => (int) env('CURSOR_HTTP_TIMEOUT_SECONDS', 60),

    /** TCP connect timeout — fail fast when production cannot reach api.cursor.com. */
    'cursor_connect_timeout_seconds' => (int) env('CURSOR_CONNECT_TIMEOUT_SECONDS', 10),

    /** Extra attempts on connection timeouts only. */
    'cursor_http_retries' => (int) env('CURSOR_HTTP_RETRIES', 1),

    /*
    |--------------------------------------------------------------------------
    | Assessment defaults (question counts & time limits)
    |--------------------------------------------------------------------------
    */
    'assessments' => [
        'quiz' => [
            'question_count' => 5,
            'time_limit_minutes' => 10,
            'title_suffix' => 'Quick Quiz',
        ],
        'test' => [
            'question_count' => 10,
            'time_limit_minutes' => 20,
            'title_suffix' => 'Listening Test',
        ],
        'exam' => [
            'question_count' => 20,
            'time_limit_minutes' => 45,
            'title_suffix' => 'Listening Exam',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Question bank size (generated once per media item after analysis)
    |--------------------------------------------------------------------------
    */
    'question_bank_count' => (int) env('FLC_LISTENING_BANK_SIZE', 30),

    /*
    |--------------------------------------------------------------------------
    | Uploaded audio constraints
    |--------------------------------------------------------------------------
    */
    'max_audio_size_mb' => (int) env('FLC_MAX_AUDIO_SIZE_MB', 50),

    'allowed_audio_mimes' => [
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/mp4',
        'audio/x-m4a',
        'audio/m4a',
        'video/mp4',
    ],

];
