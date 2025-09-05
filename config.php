<?php
declare(strict_types=1);

/**
 * config.php
 * - CLI-safe (doesn't read $_SERVER)
 * - Defines DB_* constants only; DOES NOT create a PDO
 * - Local defaults assume MAMP MySQL on port 8889
 * - Can be overridden via environment variables
 */

$APP_ENV = getenv('APP_ENV') ?: ((PHP_SAPI === 'cli') ? 'local' : 'prod');

// Allow SERVER_NAME as a hint only if present (web)
if (!getenv('APP_ENV') && isset($_SERVER['SERVER_NAME'])) {
    if (in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1'], true)) {
        $APP_ENV = 'local';
    }
}

if ($APP_ENV === 'local') {
    // Force TCP (not sockets) to avoid CLI “No such file or directory”
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
    define('DB_PORT', getenv('DB_PORT') ?: '8889'); // MAMP default MySQL port
    define('DB_NAME', getenv('DB_NAME') ?: 'mdpngsfhzc');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') ?: 'root');
    define('DB_SOCKET', getenv('DB_SOCKET') ?: '');
} else {
    define('DB_HOST', getenv('DB_HOST') ?: '157.245.209.9');
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
    define('DB_NAME', getenv('DB_NAME') ?: 'mdpngsfhzc');
    define('DB_USER', getenv('DB_USER') ?: 'mdpngsfhzc');
    define('DB_PASS', getenv('DB_PASS') ?: 'Qj4B2z3fWt');
    define('DB_SOCKET', getenv('DB_SOCKET') ?: '');
}
