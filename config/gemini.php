<?php

return [
    'api_key' => env('GEMINI_API_KEY'),
    'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
    'fallback_models' => [
        'gemini-3.6-flash',
        'gemini-3.7-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.8-flash',
    ],
    'timeout' => (int) env('GEMINI_TIMEOUT', 90),
    'provider' => env('AI_PROVIDER', 'gemini'),
];
