<?php
/**
 * PDO database connection.
 * Include this file wherever you need $pdo.
 *
 * Timezone is pinned to America/New_York for both PHP and the MySQL session
 * so NOW() and PHP's date()/strtotime() produce the same wall-clock string.
 * Without this, MySQL writes "07:40" (server-local MST) while PHP parses it
 * as UTC and relativeTime() reports rows as 7h old the moment they're written.
 */

date_default_timezone_set('America/New_York');

$config = require __DIR__ . '/config.php';

$dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";

try {
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Match PHP's timezone on the MySQL session. Use the named-zone form first
    // (works only if the host loaded the tz tables); fall back to the numeric
    // offset, which always works. PHP's date() gives us the right offset for
    // the current moment including DST.
    try {
        $pdo->exec("SET time_zone = 'America/New_York'");
    } catch (PDOException $e) {
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
} catch (PDOException $e) {
    http_response_code(500);
    // During setup, show the error. Once live, swap to a generic message.
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}
