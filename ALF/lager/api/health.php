<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = lager_pdo();
    $version = $pdo->query('SELECT VERSION() AS version')->fetch()['version'] ?? 'ukjend';
    lager_json([
        'ok' => true,
        'message' => 'Databasekoplinga fungerer.',
        'mysql_version' => $version,
    ]);
} catch (Throwable $e) {
    lager_json([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
