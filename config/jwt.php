<?php

return [
    'secret' => env('JWT_SECRET', env('APP_KEY')),
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'laravel-api')),
    'access_ttl' => (int) env('JWT_ACCESS_TTL', 15), // minutes
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 10080), // minutes (7 days)
    'clock_skew' => (int) env('JWT_CLOCK_SKEW', 60), // seconds
];
