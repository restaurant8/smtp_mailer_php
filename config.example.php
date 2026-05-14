<?php

return [
    'app_url' => 'https://mail.example.com',
    'app_secret' => 'change-this-long-random-secret',

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'smtp_mailer',
        'username' => 'smtp_mailer',
        'password' => 'change-this-password',
        'charset' => 'utf8mb4',
    ],

    'smtp' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'your-account@example.com',
        'password' => 'your-smtp-password',
        'encryption' => 'tls', // tls, ssl, or empty string
        'from_email' => 'your-account@example.com',
        'from_name' => 'Your Company',
        'reply_to' => 'support@example.com',
    ],
];
