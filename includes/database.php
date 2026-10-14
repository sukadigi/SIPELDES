<?php
declare(strict_types=1);

function load_environment(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . trim($value, "\"'"));
        }
    }
}

function database_connection(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $driver = strtolower((string) (getenv('DB_DRIVER') ?: 'sqlite'));
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if ($driver === 'sqlite') {
        $configuredPath = getenv('DB_PATH') ?: 'storage/sipeldes.sqlite';
        $path = str_starts_with($configuredPath, '/') ? $configuredPath : dirname(__DIR__) . '/' . $configuredPath;
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Direktori database tidak dapat dibuat.');
        }
        $connection = new PDO('sqlite:' . $path, null, null, $options);
        $connection->exec('PRAGMA foreign_keys = ON');
        return $connection;
    }

    if ($driver !== 'mysql') {
        throw new RuntimeException('Driver database tidak didukung.');
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: '';
    if ($name === '') {
        throw new RuntimeException('DB_NAME wajib diisi untuk MySQL.');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $connection = new PDO($dsn, getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: '', $options);
    return $connection;
}