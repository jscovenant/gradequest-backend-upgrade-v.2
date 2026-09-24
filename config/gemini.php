<?php

return [
    'api_key' => env('GEMINI_API_KEY'),
    'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    'fallback_models' => [
        'gemini-2.5-flash',
        'gemini-2.5-flash-lite',
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.6-flash',
        'gemini-3.7-flash',
        'gemini-flash-latest',
        'gemini-2.5-pro',
    ],
    'timeout' => (int) env('GEMINI_TIMEOUT', 90),
    'provider' => env('AI_PROVIDER', 'gemini'),
];
