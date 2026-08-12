<?php

return [
    'api_key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-5-mini'),
    'timeout' => (int) env('OPENAI_TIMEOUT', 90),
];
