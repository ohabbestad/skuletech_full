<?php
declare(strict_types=1);

function utstyr_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function utstyr_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = utstyr_config();
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

function utstyr_start_session(): void
{
    $config = utstyr_config();
    session_name($config['session_name'] ?? 'UTSTYR_SESS');
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function utstyr_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function utstyr_request(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function utstyr_require_role(array $allowedRoles): array
{
    utstyr_start_session();
    if (empty($_SESSION['utstyr_user']) || !is_array($_SESSION['utstyr_user'])) {
        utstyr_json(['error' => 'Du må logge inn på nytt.'], 401);
    }
    $user = $_SESSION['utstyr_user'];
    $role = (string)($user['role'] ?? '');
    if (!in_array($role, $allowedRoles, true)) {
        utstyr_json(['error' => 'Du har ikkje tilgang til denne handlinga.'], 403);
    }
    return $user;
}

function utstyr_slug(string $value): string
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
    return $value !== '' ? $value : 'utstyr';
}

function utstyr_parse_int($value, string $label, int $min = 1, int $max = 9999): int
{
    $raw = trim((string)$value);
    if ($raw === '' || !ctype_digit($raw)) {
        utstyr_json(['error' => $label . ' må vere eit heiltal.'], 400);
    }
    $number = (int)$raw;
    if ($number < $min || $number > $max) {
        utstyr_json(['error' => $label . ' må vere mellom ' . $min . ' og ' . $max . '.'], 400);
    }
    return $number;
}

function utstyr_parse_date(string $value, string $label): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        utstyr_json(['error' => $label . ' er ugyldig.'], 400);
    }
    return $dt->format('Y-m-d');
}

function utstyr_clean_text($value, int $maxLength): string
{
    $text = trim((string)$value);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
    return substr($text, 0, $maxLength);
}
