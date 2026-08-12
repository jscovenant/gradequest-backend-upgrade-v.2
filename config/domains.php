<?php

return [
    'cname_target' => strtolower(trim((string) env('CUSTOM_DOMAIN_CNAME_TARGET', 'domains.gradequest.com.ng'), '. ')),
    'verification_prefix' => env('CUSTOM_DOMAIN_VERIFICATION_PREFIX', '_gradequest-verification'),
    'platform_hosts' => array_values(array_filter(array_map(
        fn (string $host) => strtolower(trim($host, '. ')),
        explode(',', (string) env('PLATFORM_HOSTS', 'gradequest.com.ng,www.gradequest.com.ng,app.gradequest.com.ng,api.gradequest.com.ng'))
    ))),
    'target_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CUSTOM_DOMAIN_TARGET_IPS', ''))
    ))),
    'tls_ask_secret' => (string) env('CUSTOM_DOMAIN_TLS_ASK_SECRET', ''),
    'health_failure_threshold' => (int) env('CUSTOM_DOMAIN_HEALTH_FAILURE_THRESHOLD', 3),
];
