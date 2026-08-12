<?php

return [
    'mail_from_address' => env('SUPPORT_MAIL_FROM_ADDRESS', 'support@gradequest.com.ng'),
    'mail_from_name' => env('SUPPORT_MAIL_FROM_NAME', 'GradeQuest Support'),
    'frontend_url' => rtrim(env('FRONTEND_URL', 'https://gradequest.com.ng'), '/'),
];
