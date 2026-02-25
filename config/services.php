<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'api' => [
        'base_url' => env('API_BASE_URL'),
        'timeout' => env('API_TIMEOUT', 20),
        'connect_timeout' => env('API_CONNECT_TIMEOUT', 5),
        'retry' => env('API_RETRY', 2),
        'retry_delay' => env('API_RETRY_DELAY', 500),
        'force_ipv4' => env('API_FORCE_IPV4', false),
    ],

    'bdcourier' => [
        'url' => env('BDCOURIER_URL', 'https://bdcourier.com/api/courier-check'),
        'token' => env('BDCOURIER_TOKEN'),
        'timeout' => env('BDCOURIER_TIMEOUT', 20),
        'connect_timeout' => env('BDCOURIER_CONNECT_TIMEOUT', 5),
        'retry' => env('BDCOURIER_RETRY', 1),
        'retry_delay' => env('BDCOURIER_RETRY_DELAY', 500),
    ],

    'dropshipping' => [
        'seller_code' => env('EFB_SELLER_CODE'),
    ],

];
