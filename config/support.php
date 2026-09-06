<?php

return [
    'mail_from_address' => env('SUPPORT_MAIL_FROM_ADDRESS', 'support@schoolprofit.ng'),
    'mail_from_name' => env('SUPPORT_MAIL_FROM_NAME', 'SchoolProfit Support'),
    'frontend_url' => rtrim(env('FRONTEND_URL', 'https://schoolprofit.ng'), '/'),
];
