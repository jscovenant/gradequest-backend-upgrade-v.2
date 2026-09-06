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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    'monnify' => [
        'api_key' => env('MONNIFY_API_KEY'),
        'secret_key' => env('MONNIFY_SECRET_KEY'),
        'contract_code' => env('MONNIFY_CONTRACT_CODE'),
        'base_url' => env('MONNIFY_BASE_URL'),
    ],

    'wema_alat' => [
        'alatpay_key' => env('WEMA_ALAT_ALATPAY_KEY', 'f325c0f65b3b4758bf9e0c81fcc23bd6'),
        'payout_key' => env('WEMA_ALAT_PAYOUT_KEY', '1eb9d69581404ba4b89d856b7147711a'),
        'virtual_account_key' => env('WEMA_ALAT_VIRTUAL_ACCOUNT_KEY', 'schooproft_virtual_acct_pending'),
        'base_url' => env('WEMA_ALAT_BASE_URL', 'https://wema-alatdev-apimgt.azure-api.net'),
        'corporate_account' => env('WEMA_CORPORATE_ACCOUNT_NUMBER', '0123456789'),
        'webhook_secret' => env('WEMA_ALAT_WEBHOOK_SECRET', 'sp_wema_webhook_secret_2026'),
        'env' => env('WEMA_ALAT_ENV', 'sandbox'),
    ],


    'paystack' => [
        'secret' => env('PAYSTACK_SECRET_KEY'),
        'platform_fee_naira' => env('PAYSTACK_PLATFORM_FEE_NAIRA', 1000),
    ],

    'paystack_public' => [
        'public' => env('PAYSTACK_PUBLIC_KEY'),
    ],



 

    'twilio' => [
        'sid'        => env('TWILIO_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from'       => env('TWILIO_WHATSAPP_FROM'),
    ],

    'turnstile' => [
        'secret'  => env('CLOUDFLARE_TURNSTILE_SECRET_KEY'),
        'enabled' => env('CLOUDFLARE_TURNSTILE_ENABLED', true),
    ],

];
