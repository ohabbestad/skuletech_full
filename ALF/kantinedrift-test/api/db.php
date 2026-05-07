<?php
declare(strict_types=1);

function kantine_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function kantine_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = kantine_config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_name']
    );

    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function kantine_start_session(): void
{
    $config = kantine_config();
    session_name($config['session_name'] ?? 'KANTINE_TEST_SESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function kantine_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function kantine_request(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function kantine_require_role(array $allowedRoles): array
{
    kantine_start_session();
    if (empty($_SESSION['kantine_user']) || !is_array($_SESSION['kantine_user'])) {
        kantine_json(['error' => 'Du må logge inn på nytt.'], 401);
    }
    $user = $_SESSION['kantine_user'];
    if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
        kantine_json(['error' => 'Du har ikkje tilgang til denne handlinga.'], 403);
    }
    return $user;
}
