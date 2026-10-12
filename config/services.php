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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],
    'tap' => [
    'secret_key' => env('TAP_SECRET_KEY'),
    'public_key'  => env('TAP_PUBLIC_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'paddle' => [
    'env'        => env('PADDLE_ENV', 'sandbox'),
    'secret_key' => env('PADDLE_SECRET_KEY'),
    'public_key' => env('PADDLE_PUBLIC_KEY'),
    'price_id'   => env('PADDLE_PRICE_ID', null),
    'webhook_secret' => env('PADDLE_WEBHOOK_SECRET', null),
],
    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
