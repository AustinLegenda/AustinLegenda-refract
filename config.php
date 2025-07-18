<?php

// Detect environment based on server name
$is_local = in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1']) || php_sapi_name() === 'cli';

if ($is_local) {
    // Local DB settings
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'mdpngsfhzc');
    define('DB_USER', 'root');
    define('DB_PASS', 'root');
    $conn = new PDO("mysql:host=localhost;dbname=mdpngsfhzc", "root", "root");
} else {
    // Remote/production DB settings
    define('DB_HOST', '157.245.209.9');
    define('DB_NAME', 'mdpngsfhzc');
    define('DB_USER', 'mdpngsfhzc');
    define('DB_PASS', 'Qj4B2z3fWt');
    $conn = new PDO("mysql:host=157.245.209.9;dbname=mdpngsfhzc", "mdpngsfhzc", "Qj4B2z3fWt");
} ?>