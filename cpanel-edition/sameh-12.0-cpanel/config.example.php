<?php
/**
 * SAMEH 12.0 cPanel Edition — copy to config.php (preferred outside public/)
 * or to config.local.php next to this file.
 *
 * cPanel: copy this file to ../config.php (one level above public/) OR
 * keep as /sameh-12.0-cpanel/config.local.php and ensure web cannot serve it
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
];
