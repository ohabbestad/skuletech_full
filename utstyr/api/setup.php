<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$config = utstyr_config();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $setupKey = (string)($_POST['setup_key'] ?? '');
        if (!hash_equals((string)$config['setup_key'], $setupKey)) {
            throw new RuntimeException('Feil setup-passord.');
        }

        $users = [
            ['tilsett', 'tilsett', (string)($_POST['tilsett_password'] ?? '')],
            ['admin', 'admin', (string)($_POST['admin_password'] ?? '')],
        ];

        $stmt = utstyr_pdo()->prepare(
            'INSERT INTO utstyr_users (username, role, password_hash, active)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), active = 1'
        );

        foreach ($users as [$username, $role, $password]) {
            if ($password === '') {
                continue;
            }
            $stmt->execute([$username, $role, password_hash($password, PASSWORD_DEFAULT)]);
        }

        $message = 'Passord er oppdatert.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="nn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Oppsett for utstyrslån</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-6">
  <main class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 w-full max-w-lg">
    <h1 class="text-2xl font-bold text-slate-900 mb-2">Oppsett for utstyrslån</h1>
    <p class="text-slate-600 mb-6">Bruk denne sida til å lage eller byte passord for undervisningsmateriell.</p>

    <?php if ($message): ?>
      <p class="mb-4 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
      <p class="mb-4 rounded bg-red-50 border border-red-200 text-red-800 px-4 py-3"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" class="space-y-4">
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Setup-passord</span>
        <input name="setup_key" type="password" required class="mt-1 w-full border border-slate-300 rounded-lg p-3">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Nytt passord for tilsette</span>
        <input name="tilsett_password" type="password" class="mt-1 w-full border border-slate-300 rounded-lg p-3">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Nytt passord for admin</span>
        <input name="admin_password" type="password" class="mt-1 w-full border border-slate-300 rounded-lg p-3">
      </label>
      <button class="w-full bg-sky-700 hover:bg-sky-800 text-white font-semibold rounded-lg p-3">Lagre passord</button>
    </form>
  </main>
</body>
</html>
