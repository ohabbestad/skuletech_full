<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $pdo = kantine_pdo();
    $version = $pdo->query('SELECT VERSION() AS version')->fetch()['version'] ?? 'ukjend';
    kantine_json([
        'ok' => true,
        'message' => 'Databasekoplinga fungerer.',
        'mysql_version' => $version,
    ]);
} catch (Throwable $e) {
    kantine_json([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
