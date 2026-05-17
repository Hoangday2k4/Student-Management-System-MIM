<?php
// Đảm bảo luôn require define.php để có DB_PATH
require_once __DIR__ . '/define.php';

function get_db_connection(): PDO
{
    static $instance = null;
    if ($instance !== null) {
        return $instance;
    }
    try {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('missing pdo_sqlite');
        }
        $dsn = 'sqlite:' . DB_PATH;
        $instance = new PDO($dsn, '', '', [
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $instance->exec('PRAGMA busy_timeout = 10000');
        $instance->exec('PRAGMA journal_mode = WAL');
        $instance->exec('PRAGMA foreign_keys = ON');
        $instance->exec('PRAGMA cache_size = -8000');
        $instance->exec('PRAGMA temp_store = MEMORY');
        return $instance;
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['error' => 'Database connection failed', 'message' => $e->getMessage()],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        exit;
    }
}
