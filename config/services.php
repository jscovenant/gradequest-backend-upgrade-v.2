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
        'business_id' => env('WEMA_ALAT_BUSINESS_ID', '170d0720-1287-49ec-8d91-4c42b6a53c22'),
        'public_key' => env('WEMA_ALAT_PUBLIC_KEY', 'd7ec83fb3f7d48e19b9ef3d417778ed7'),
        'secret_key' => env('WEMA_ALAT_SECRET_KEY', '2433e36e6f5f4998a2b3bee6a7bfa66e'),
        'alatpay_key' => env('WEMA_ALAT_ALATPAY_KEY', '2433e36e6f5f4998a2b3bee6a7bfa66e'),
        'payout_key' => env('WEMA_ALAT_PAYOUT_KEY', '2433e36e6f5f4998a2b3bee6a7bfa66e'),
        'virtual_account_key' => env('WEMA_ALAT_VIRTUAL_ACCOUNT_KEY', '2433e36e6f5f4998a2b3bee6a7bfa66e'),
        'base_url' => env('WEMA_ALAT_BASE_URL', 'https://apibox.alatpay.ng'),
        'corporate_account' => env('WEMA_CORPORATE_ACCOUNT_NUMBER', '0128168785'),
        'corporate_account_name' => env('WEMA_ALAT_CORPORATE_ACCOUNT_NAME', 'Samaritan Technologies'),
        'webhook_secret' => env('WEMA_ALAT_WEBHOOK_SECRET', '77f4b0c692cef9f0cb546612751213f8'),
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
