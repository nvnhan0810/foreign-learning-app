<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed emails (Google sign-in)
    |--------------------------------------------------------------------------
    |
    | Comma-separated in .env as FLC_ALLOWED_EMAILS.
    | Exact: user@gmail.com
    | Domain wildcard: *@company.com
    |
    | When FLC_ALLOW_ALL_EMAILS=true, every Google account is accepted (dev only).
    | When the list is empty and allow_all is false, nobody can sign in.
    |
    */
    'allowed_emails' => array_values(array_filter(array_map(
        static fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('FLC_ALLOWED_EMAILS', ''))
    ))),

    'allow_all_emails' => (bool) env('FLC_ALLOW_ALL_EMAILS', false),

    /*
    |--------------------------------------------------------------------------
    | Admin panel (Google sign-in)
    |--------------------------------------------------------------------------
    |
    | Comma-separated in FLC_ADMIN_EMAILS — only these emails can access /admin
    |
    */
    'admin_emails' => array_values(array_filter(array_map(
        static fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('FLC_ADMIN_EMAILS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Dictionary lookup resolve (extension quick lookup)
    |--------------------------------------------------------------------------
    */
    'lookup_resolve_enable_datamuse' => (bool) env('LOOKUP_RESOLVE_ENABLE_DATAMUSE', true),

    /*
    |--------------------------------------------------------------------------
    | Dictionary upstreams (failover)
    |--------------------------------------------------------------------------
    |
    | Priority: dictionaryapi.dev → freedictionaryapi.com → api.suvankar.cc
    | Unavailable sources (timeout / 5xx / 429) are locked and skipped until TTL.
    |
    */
    'dictionary_upstreams' => [
        'timeout_seconds' => (int) env('DICTIONARY_UPSTREAM_TIMEOUT', 8),
        'lock_seconds' => (int) env('DICTIONARY_SOURCE_LOCK_SECONDS', 900),
        'rate_limit_lock_seconds' => (int) env('DICTIONARY_SOURCE_RATE_LIMIT_LOCK_SECONDS', 3600),
        'sources' => [
            'dictionaryapi_dev' => [
                'url' => env('DICTIONARY_API_DEV_URL', 'https://api.dictionaryapi.dev/api/v2/entries/en'),
            ],
            'freedictionaryapi' => [
                'url' => env('DICTIONARY_FREEDICTIONARYAPI_URL', 'https://freedictionaryapi.com/api/v1/entries/en'),
            ],
            'suvankar' => [
                'url' => env('DICTIONARY_SUVANKAR_URL', 'https://api.suvankar.cc/dictionaryapi/v1/definitions/en'),
            ],
        ],
    ],

];
