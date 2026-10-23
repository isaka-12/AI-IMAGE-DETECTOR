<?php
declare(strict_types=1);

/** Shared helpers for the JSON endpoints (scan.php, history.php). */

function send_json(int $code, array $data): never
{
    http_response_code($code);
    // metadata strings come from the upload and may not be valid UTF-8
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** One PDO connection over the local MariaDB socket (no TCP, see entrypoint.sh). Times are UTC. */
function db(): PDO
{
    return new PDO(
        'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=scanner;charset=utf8mb4',
        'scanner',
        $_SERVER['DB_PASS'] ?? '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]
    );
}

/** Session owner tag: lets a visitor see their own file names without exposing the session id */
function owner_tag(): string
{
    return session_id() === '' ? '' : hash('sha256', session_id());
}
