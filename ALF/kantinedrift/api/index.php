<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/operations.php';
header('Cache-Control: no-store');
kantine_start_session();
try {
    $data = kantine_request();
    $action = $data['action'] ?? 'load';
    $role = $data['role'] ?? '';
    if ($action === 'logout') { kantine_destroy_session(); kantine_json(['ok' => true]); }
    if ($action === 'load' && $role === 'infoskjerm') kantine_json(kv_public());
    kv_assert_migrated();
    if (!empty($data['password'])) login_with_password((string)$role, (string)$data['password']);
    $user = kantine_require_role(['tilsett', 'driftsleiar', 'laerar']);
    if ($role !== $user['role'] && $user['role'] !== 'laerar') kantine_json(['error' => 'Logg inn med riktig rolle.'], 403);
    if ($action === 'save') kv_save($data, $user);
    elseif ($action !== 'load') kantine_json(['error' => 'Ukjend handling.'], 400);
    kantine_json(kv_load());
} catch (InvalidArgumentException $e) {
    kantine_json(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('KantineVeke: ' . $e->getMessage());
    kantine_json(['error' => 'Serveren kunne ikkje fullføre handlinga. Prøv igjen.'], 500);
}