<?php
/**
 * PDO database connection.
 * Include this file wherever you need $pdo.
 */

$config = require __DIR__ . '/config.php';

$dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";

try {
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    // During setup, show the error. Once live, swap to a generic message.
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}
