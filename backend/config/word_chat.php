<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cursor API (shared with listening / web agent — see config/listening.php)
    |--------------------------------------------------------------------------
    |
    | Word chat uses the same CURSOR_API_KEY and CURSOR_API_BASE as listening.
    | Only word-chat-specific timeouts and limits live here.
    |
    */

    'cursor_stream_timeout_seconds' => (int) env('WORD_CHAT_STREAM_TIMEOUT_SECONDS', 300),

    'cursor_create_timeout_seconds' => (int) env('WORD_CHAT_CREATE_TIMEOUT_SECONDS', 180),

    'cursor_http_timeout_seconds' => (int) env('WORD_CHAT_HTTP_TIMEOUT_SECONDS', 90),

    'cursor_connect_timeout_seconds' => (int) env('WORD_CHAT_CONNECT_TIMEOUT_SECONDS', 10),

    'cursor_http_retries' => (int) env('WORD_CHAT_HTTP_RETRIES', 1),

    'max_message_length' => (int) env('WORD_CHAT_MAX_MESSAGE_LENGTH', 4000),

    'history_page_size' => (int) env('WORD_CHAT_HISTORY_PAGE_SIZE', 50),

    'agent_provision_stale_seconds' => (int) env('WORD_CHAT_AGENT_PROVISION_STALE_SECONDS', 180),

    'cursor_stream_open_attempts' => (int) env('WORD_CHAT_STREAM_OPEN_ATTEMPTS', 20),

    'cursor_stream_open_delay_ms' => (int) env('WORD_CHAT_STREAM_OPEN_DELAY_MS', 1000),

    'cursor_stream_wait_seconds' => (int) env('WORD_CHAT_STREAM_WAIT_SECONDS', 30),

    'cursor_warmup_run_wait_seconds' => (int) env('WORD_CHAT_WARMUP_RUN_WAIT_SECONDS', 180),

    /*
    | Bump when Word Chat system rules change. Active agents with an older version
    | are archived and recreated on the next ensure call.
    */
    'prompt_version' => (int) env('WORD_CHAT_PROMPT_VERSION', 4),

];
