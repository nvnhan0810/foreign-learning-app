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

];
