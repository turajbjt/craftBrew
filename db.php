<?php
/**
 * Database Singleton Helper
 * Supports SQLite (default) and MySQL backends.
 */

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $engine = strtolower(defined('DB_ENGINE') ? DB_ENGINE : 'sqlite');
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                if ($engine === 'sqlite') {
                    $sqlitePath = defined('DB_SQLITE_PATH') ? DB_SQLITE_PATH : __DIR__ . '/data/recurring_mgt.sqlite';
                    $dbDir = dirname($sqlitePath);

                    if (!is_dir($dbDir)) {
                        if (!@mkdir($dbDir, 0777, true) && !is_dir($dbDir)) {
                            die("Database Error: Unable to create database directory '{$dbDir}'. Please check folder permissions.\n");
                        }
                    }

                    // Ensure write permissions on database folder for web server process
                    @chmod($dbDir, 0777);

                    $isNewDatabase = !file_exists($sqlitePath) || filesize($sqlitePath) === 0;

                    if (!file_exists($sqlitePath)) {
                        if (@touch($sqlitePath)) {
                            @chmod($sqlitePath, 0666);
                        }
                    }

                    $dsn = 'sqlite:' . $sqlitePath;
                    self::$instance = new PDO($dsn, null, null, $options);
                    self::$instance->exec("PRAGMA foreign_keys = ON;");

                    // Auto-initialize SQLite schema if tables do not exist yet
                    $checkTable = self::$instance->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
                    if (!$checkTable || $isNewDatabase) {
                        self::initSqliteDatabase(self::$instance);
                    }
                } else {
                    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
                    self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                }
            } catch (PDOException $e) {
                // Return a clean error if running CLI or Web
                die("Database Connection Error: " . $e->getMessage() . "\n");
            }
        }
        return self::$instance;
    }

    private static function initSqliteDatabase(PDO $pdo): void {
        $schemaFile = __DIR__ . '/schema_sqlite.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $pdo->exec($sql);
        }
    }
}
