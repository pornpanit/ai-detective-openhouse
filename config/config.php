<?php
// Runtime configuration. Credentials come from environment variables in
// production (Azure app settings); local dev falls back to the portable stack.
return [
    'research' => [
        'dsn'  => 'mysql:host=127.0.0.1;port=3307;dbname=ai_detect_research;charset=utf8mb4',
        'user' => getenv('RESEARCH_DB_USER') ?: 'research_app',
        'pass' => getenv('RESEARCH_DB_PASS') ?: 'research_dev_pw',
    ],
    'prize' => [
        'dsn'  => 'mysql:host=127.0.0.1;port=3307;dbname=ai_detect_prize;charset=utf8mb4',
        'user' => getenv('PRIZE_DB_USER') ?: 'prize_app',
        'pass' => getenv('PRIZE_DB_PASS') ?: 'prize_dev_pw',
    ],
    'manifest' => dirname(__DIR__) . '/public/assets/pool_manifest.csv',
    'window_minutes' => 30,
    'admin_pw' => getenv('ADMIN_PW') ?: 'adminlocal',
];
