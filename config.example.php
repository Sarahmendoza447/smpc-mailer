<?php

return [
    'shared_api_key' => 'replace_with_shared_api_key',
    'transport' => 'smtp',
    'default_from_email' => 'hr@example.com',
    'default_from_name' => 'Serendipity HR',
    'default_reply_to' => 'hr@example.com',
    'frontend_base_url' => 'http://localhost:5173',
    'allowed_origins' => [
        'http://localhost:5173',
        'https://your-frontend.example.com',
    ],
    'supabase' => [
        'url' => 'https://your-project.supabase.co',
        'service_role_key' => 'replace_with_service_role_key',
    ],
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'hr@example.com',
        'password' => 'replace_with_app_password',
        'timeout' => 30,
        'ehlo_host' => 'localhost',
    ],
    'resend_api_key' => '',
];
