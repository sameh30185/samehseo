<?php
/**
 * SAMEH 12.1 Professional — copy to config.php (preferred outside public/)
 * or to config.local.php next to this file.
 *
 * cPanel: copy this file to ../config.php (one level above public/) OR
 * keep as config.local.php and ensure web cannot serve it
 * (document root must be public/).
 */
return [
    'db_host' => 'localhost',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'app_url' => 'https://sameh.example.com',
    'session_name' => 'sameh_sess',
    // Optional: force HTTPS cookies (auto-detected from app_url if https)
    'secure_cookies' => true,
    // 32+ char secret for encrypting API keys at rest (recommended)
    'app_key' => '',
    // SMTP (optional). If unset, password-reset emails go to storage/mail-outbox/
    // Required for real email: smtp_host, smtp_from; optional smtp_port, smtp_user, smtp_pass, smtp_encryption (tls|ssl|none)
    'smtp_host' => '',
    'smtp_port' => 587,
    'smtp_user' => '',
    'smtp_pass' => '',
    'smtp_from' => '',
    'smtp_encryption' => 'tls',
    // Absolute path to emergency recovery key file OUTSIDE public web root.
    // File contents = raw key string. Never commit this file. Constant-time compared.
    // 'emergency_recovery_key_file' => '/home/USER/sameh-emergency.key',
];
