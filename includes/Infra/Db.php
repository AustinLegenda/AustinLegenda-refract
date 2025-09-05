<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\Infra;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        require_once \dirname(__DIR__, 2) . '/config.php';

        $buildDsn = static function (?string $socket, ?string $host, ?string $port): string {
            if ($socket) {
                return 'mysql:unix_socket=' . $socket . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            }
            $p = $port ?: '3306';
            return 'mysql:host=' . ($host ?: '127.0.0.1') . ';port=' . $p . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        };

        $attempts = [];

        // 1) Prefer socket if provided
        $sock = (\defined('DB_SOCKET') && DB_SOCKET !== '') ? DB_SOCKET : null;
        if ($sock) {
            $attempts[] = ['socket' => $sock, 'host' => null, 'port' => null];
        }

        // 2) Then TCP with configured port
        $attempts[] = ['socket' => null, 'host' => \defined('DB_HOST') ? DB_HOST : '127.0.0.1', 'port' => \defined('DB_PORT') ? DB_PORT : '3306'];

        // 3) Finally, a last-resort TCP 3306
        $attempts[] = ['socket' => null, 'host' => '127.0.0.1', 'port' => '3306'];

        $lastError = null;

        foreach ($attempts as $a) {
            $dsn = $buildDsn($a['socket'], $a['host'], $a['port']);
            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                // Optional: align session TZ if you depend on NOW()
                // self::$pdo->exec("SET time_zone = '+00:00'");
                return self::$pdo;
            } catch (\PDOException $e) {
                $lastError = $e;
            }
        }

        // Enrich the error so you know what it tried
        $attemptStr = array_map(function ($a) {
            return $a['socket']
                ? "socket={$a['socket']}"
                : "host={$a['host']};port={$a['port']}";
        }, $attempts);

        $msg = 'Database connection failed. Attempts: [' . implode(' | ', $attemptStr) . ']. '
             . 'APP_ENV=' . (getenv('APP_ENV') ?: 'unset') . '. '
             . 'Original error: ' . ($lastError ? $lastError->getMessage() : 'unknown');

        throw new \PDOException($msg, (int)($lastError?->getCode() ?: 0), $lastError);
    }

    public static function close(): void
    {
        self::$pdo = null;
    }
}
