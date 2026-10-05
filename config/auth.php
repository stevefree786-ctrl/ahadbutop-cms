<?php
/**
 * Authentication + authorization configuration.
 */
return [
    'jwt' => [
        'secret'      => env('JWT_SECRET', ''),
        'algorithm'   => 'HS256',
        'issuer'      => env('APP_URL', 'http://localhost:8080'),
        'ttl'         => (int) env('JWT_TTL', 3600),          // seconds
        'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 604800), // 7 days
    ],

    'password' => [
        // argon2id is preferred; falls back to bcrypt via PASSWORD_DEFAULT on older builds
        'algo' => PASSWORD_DEFAULT,
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
    ],

    'lockout' => [
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'window'      => (int) env('LOGIN_LOCKOUT_WINDOW', 900), // 15 min in seconds
    ],

    // Minimum role required per HTTP method on content routes.
    // Read = author, write = editor, destructive/settings = admin.
    'permissions' => [
        'GET'    => 'author',
        'POST'   => 'editor',
        'PUT'    => 'editor',
        'PATCH'  => 'editor',
        'DELETE' => 'admin',
    ],
];