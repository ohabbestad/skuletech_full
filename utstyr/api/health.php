<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = utstyr_pdo();
    $version = $pdo->query('SELECT VERSION() AS version')->fetch()['version'] ?? 'ukjend';
    utstyr_json([
        'ok' => true,
        'message' => 'Databasekoplinga fungerer.',
        'mysql_version' => $version,
    ]);
} catch (Throwable $e) {
    utstyr_json([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
