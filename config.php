<?php
declare(strict_types=1);

$APP_ENV = getenv('APP_ENV') ?: ((PHP_SAPI === 'cli') ? 'local' : 'prod');

// Optional web hint
if (!getenv('APP_ENV') && isset($_SERVER['SERVER_NAME'])) {
    if (in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1'], true)) {
        $APP_ENV = 'local';
    }
}

if ($APP_ENV === 'local') {
    // MAMP defaults
    $defaultSock = '/Applications/MAMP/tmp/mysql/mysql.sock';
    $sockEnv     = getenv('DB_SOCKET') ?: null;
    $sockPath    = $sockEnv ?: (is_file($defaultSock) ? $defaultSock : null);

    if (!defined('DB_HOST'))   define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
    if (!defined('DB_PORT'))   define('DB_PORT', getenv('DB_PORT') ?: '8889'); // MAMP port
    if (!defined('DB_NAME'))   define('DB_NAME', getenv('DB_NAME') ?: 'mdpngsfhzc');
    if (!defined('DB_USER'))   define('DB_USER', getenv('DB_USER') ?: 'root');
    if (!defined('DB_PASS'))   define('DB_PASS', getenv('DB_PASS') ?: 'root');
    if (!defined('DB_SOCKET')) define('DB_SOCKET', $sockPath ?: '');
} else {
    if (!defined('DB_HOST'))   define('DB_HOST', getenv('DB_HOST') ?: '157.245.209.9');
    if (!defined('DB_PORT'))   define('DB_PORT', getenv('DB_PORT') ?: '3306');
    if (!defined('DB_NAME'))   define('DB_NAME', getenv('DB_NAME') ?: 'mdpngsfhzc');
    if (!defined('DB_USER'))   define('DB_USER', getenv('DB_USER') ?: 'mdpngsfhzc');
    if (!defined('DB_PASS'))   define('DB_PASS', getenv('DB_PASS') ?: 'Qj4B2z3fWt');
    if (!defined('DB_SOCKET')) define('DB_SOCKET', getenv('DB_SOCKET') ?: '');
}
