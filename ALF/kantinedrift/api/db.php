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
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config = kantine_config();
    $lifetime = kantine_session_lifetime_seconds();
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.cookie_lifetime', '0');

    session_name($config['session_name'] ?? 'KANTINE_SESS');
    session_set_cookie_params(kantine_session_cookie_options(0));
    session_start();
}

function kantine_session_lifetime_seconds(): int
{
    $config = kantine_config();
    $lifetime = (int)($config['session_lifetime_seconds'] ?? 28800);
    return max(300, $lifetime);
}

function kantine_session_cookie_options(int $lifetime): array
{
    return [
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function kantine_destroy_session(): void
{
    kantine_start_session();
    $cookieName = session_name();
    $_SESSION = [];
    session_destroy();

    $cookieOptions = kantine_session_cookie_options(0);
    unset($cookieOptions['lifetime']);
    $cookieOptions['expires'] = time() - 3600;
    setcookie($cookieName, '', $cookieOptions);
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

    $now = time();
    $lastSeen = (int)($_SESSION['kantine_last_seen_at'] ?? $now);
    if ($now - $lastSeen > kantine_session_lifetime_seconds()) {
        kantine_destroy_session();
        kantine_json(['error' => 'Økta er utgått. Logg inn på nytt.'], 401);
    }

    $_SESSION['kantine_last_seen_at'] = $now;
    $user = $_SESSION['kantine_user'];
    if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
        kantine_json(['error' => 'Du har ikkje tilgang til denne handlinga.'], 403);
    }
    return $user;
}
