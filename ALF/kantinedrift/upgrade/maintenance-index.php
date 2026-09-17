<?php
declare(strict_types=1);
// Temporarily upload this file as api/index.php before the database migration.
// Replace it with the new normal api/index.php only after all other files are ready.
http_response_code(503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Retry-After: 300');
echo json_encode([
    'error' => 'KantineVeke blir oppgradert. Registrering er mellombels stengd. Prøv igjen om litt.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
