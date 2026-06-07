<?php
declare(strict_types=1);

function lager_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function lager_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = lager_config();
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

function lager_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config = lager_config();
    $lifetime = lager_session_lifetime_seconds();
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.cookie_lifetime', '0');

    session_name($config['session_name'] ?? 'LAGER_SESS');
    session_set_cookie_params(lager_session_cookie_options(0));
    session_start();
}

function lager_session_lifetime_seconds(): int
{
    $config = lager_config();
    $lifetime = (int)($config['session_lifetime_seconds'] ?? 28800);
    return max(300, $lifetime);
}

function lager_session_cookie_options(int $lifetime): array
{
    return [
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function lager_destroy_session(): void
{
    lager_start_session();
    $cookieName = session_name();
    $_SESSION = [];
    session_destroy();

    $cookieOptions = lager_session_cookie_options(0);
    unset($cookieOptions['lifetime']);
    $cookieOptions['expires'] = time() - 3600;
    setcookie($cookieName, '', $cookieOptions);
}

function lager_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lager_request(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function lager_require_role(array $allowedRoles): array
{
    lager_start_session();
    if (empty($_SESSION['lager_user']) || !is_array($_SESSION['lager_user'])) {
        lager_json(['error' => 'Du må logge inn på nytt.'], 401);
    }

    $now = time();
    $lastSeen = (int)($_SESSION['lager_last_seen_at'] ?? $now);
    if ($now - $lastSeen > lager_session_lifetime_seconds()) {
        lager_destroy_session();
        lager_json(['error' => 'Økta er utgått. Logg inn på nytt.'], 401);
    }

    $_SESSION['lager_last_seen_at'] = $now;
    $user = $_SESSION['lager_user'];
    if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
        lager_json(['error' => 'Du har ikkje tilgang til denne handlinga.'], 403);
    }
    return $user;
}

function lager_parse_decimal($value, string $label, bool $allowNegative = false): float
{
    $raw = trim(str_replace(',', '.', (string)$value));
    if ($raw === '' || !is_numeric($raw)) {
        lager_json(['error' => $label . ' må vere eit tal.'], 400);
    }

    $number = round((float)$raw, 2);
    if (!$allowNegative && $number < 0) {
        lager_json(['error' => $label . ' kan ikkje vere negativ.'], 400);
    }
    return $number;
}

function lager_slug(string $value): string
{
    $value = function_exists('mb_strtolower')
        ? mb_strtolower(trim($value), 'UTF-8')
        : strtolower(trim($value));
    $map = [
        'Æ' => 'ae', 'Ø' => 'o', 'Å' => 'a',
        'æ' => 'ae', 'ø' => 'o', 'å' => 'a',
        'É' => 'e', 'È' => 'e', 'Ü' => 'u',
        'é' => 'e', 'è' => 'e', 'ü' => 'u',
    ];
    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'vare';
}
