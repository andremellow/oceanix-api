<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bootstrap administrators
    |--------------------------------------------------------------------------
    |
    | Comma-separated emails that receive the admin access profile on sign-in. Used to
    | bootstrap a fresh environment; day-to-day administration happens through access
    | profiles.
    |
    */

    'admin_emails' => array_values(array_filter(array_map(
        fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('ADMIN_EMAILS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | User provisioning
    |--------------------------------------------------------------------------
    |
    | When false, only people who already exist locally can sign in — the expected mode
    | once WorkOS Directory Sync provisions the workforce. Keep it true while onboarding
    | an environment by hand.
    |
    */

    'auto_provision_users' => filter_var(env('OCEANIX_AUTO_PROVISION_USERS', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Local development sign-in
    |--------------------------------------------------------------------------
    |
    | Email allowed to sign in without the identity provider, so the application can be
    | opened before WorkOS is configured. The route only exists when the application
    | environment is `local` AND this value is set — it is never registered in any other
    | environment, and it can only authenticate this exact address.
    |
    */

    'local_auth_email' => env('LOCAL_AUTH_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Playback authorization
    |--------------------------------------------------------------------------
    |
    | Lifetime of a signed video token, in minutes. Short enough that a copied URL stops
    | working quickly, long enough to watch a lesson without re-authorizing constantly.
    |
    */

    'playback_token_minutes' => (int) env('OCEANIX_PLAYBACK_TOKEN_MINUTES', 20),

    /*
    |--------------------------------------------------------------------------
    | Compliance windows
    |--------------------------------------------------------------------------
    */

    'due_soon_days' => (int) env('OCEANIX_DUE_SOON_DAYS', 14),
    'critical_overdue_days' => (int) env('OCEANIX_CRITICAL_OVERDUE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Certificates
    |--------------------------------------------------------------------------
    */

    'certificates' => [
        'disk' => env('OCEANIX_CERTIFICATE_DISK', 'local'),
        'number_prefix' => env('OCEANIX_CERTIFICATE_PREFIX', 'OCX'),
    ],

];
