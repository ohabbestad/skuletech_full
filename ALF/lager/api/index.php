<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $data = lager_request();
    $action = (string)($data['action'] ?? 'admin_load');

    if ($action === 'public_item') {
        lager_json(load_public_item((string)($data['token'] ?? '')));
    }

    if ($action === 'public_movement') {
        lager_json(register_public_movement($data));
    }

    lager_start_session();

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        lager_json(['ok' => true]);
    }

    if (!empty($data['password'])) {
        login_with_password((string)($data['role'] ?? ''), (string)$data['password']);
    }

    if ($action === 'admin_load') {
        $user = lager_require_role(['driftsleiar', 'laerar']);
        ensure_requested_role($data, $user);
        lager_json(load_admin_data($user));
    }

    if ($action === 'save_count') {
        $user = lager_require_role(['driftsleiar', 'laerar']);
        lager_json(save_count($data, $user));
    }

    if ($action === 'report') {
        $user = lager_require_role(['driftsleiar', 'laerar']);
        lager_json(load_report($data, $user));
    }

    if ($action === 'save_item') {
        $user = lager_require_role(['laerar']);
        lager_json(save_item($data, $user));
    }

    if ($action === 'create_item') {
        $user = lager_require_role(['laerar']);
        lager_json(create_item($data, $user));
    }

    if ($action === 'save_department') {
        $user = lager_require_role(['laerar']);
        lager_json(save_department($data, $user));
    }

    lager_json(['error' => 'Ukjend handling.'], 400);
} catch (Throwable $e) {
    lager_json(['error' => 'Serverfeil: ' . $e->getMessage()], 500);
}

function login_with_password(string $role, string $password): void
{
    if (!in_array($role, ['driftsleiar', 'laerar'], true)) {
        lager_json(['error' => 'Ukjend rolle.'], 400);
    }

    $stmt = lager_pdo()->prepare(
        'SELECT id, username, role, password_hash FROM lager_users WHERE role = ? AND active = 1 LIMIT 1'
    );
    $stmt->execute([$role]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        lager_json(['error' => 'Feil passord.'], 401);
    }

    $_SESSION['lager_user'] = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
    ];
}

function ensure_requested_role(array $data, array $user): void
{
    $requestedRole = (string)($data['role'] ?? ($user['role'] ?? ''));
    if ($requestedRole !== '' && ($user['role'] ?? '') !== $requestedRole && ($user['role'] ?? '') !== 'laerar') {
        lager_json(['error' => 'Du må logge inn med riktig rolle.'], 403);
    }
}

function load_public_item(string $token): array
{
    $token = trim($token);
    if ($token === '') {
        lager_json(['error' => 'Manglar varekode.'], 400);
    }

    $stmt = lager_pdo()->prepare(
        'SELECT i.id, i.category_id, i.name, i.unit, i.stock_quantity, i.min_quantity, i.shelf_label,
                i.qr_token, i.active, c.name AS category_name
           FROM lager_items i
           JOIN lager_categories c ON c.id = i.category_id
          WHERE i.qr_token = ? AND i.active = 1
          LIMIT 1'
    );
    $stmt->execute([$token]);
    $item = $stmt->fetch();
    if (!$item) {
        lager_json(['error' => 'Fann ikkje vara.'], 404);
    }

    return [
        'item' => map_item($item),
        'departments' => load_departments(true),
    ];
}

function register_public_movement(array $data): array
{
    $token = trim((string)($data['token'] ?? ''));
    $type = (string)($data['movementType'] ?? '');
    if (!in_array($type, ['in', 'out'], true)) {
        lager_json(['error' => 'Vel om varen blir henta ut eller lagt inn.'], 400);
    }

    $quantity = lager_parse_decimal($data['quantity'] ?? '', 'Mengda');
    if ($quantity <= 0) {
        lager_json(['error' => 'Mengda må vere større enn null.'], 400);
    }

    $departmentId = null;
    if ($type === 'out') {
        $departmentId = (int)($data['departmentId'] ?? 0);
        if ($departmentId <= 0) {
            lager_json(['error' => 'Vel kva avdeling som hentar ut vara.'], 400);
        }
    } elseif (!empty($data['departmentId'])) {
        $departmentId = (int)$data['departmentId'];
    }

    $note = trim((string)($data['note'] ?? ''));
    if (strlen($note) > 255) {
        $note = substr($note, 0, 255);
    }

    $pdo = lager_pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, stock_quantity FROM lager_items WHERE qr_token = ? AND active = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute([$token]);
        $item = $stmt->fetch();
        if (!$item) {
            $pdo->rollBack();
            lager_json(['error' => 'Fann ikkje vara.'], 404);
        }

        if ($departmentId !== null) {
            $deptStmt = $pdo->prepare('SELECT id FROM lager_departments WHERE id = ? AND active = 1 LIMIT 1');
            $deptStmt->execute([$departmentId]);
            if (!$deptStmt->fetch()) {
                $pdo->rollBack();
                lager_json(['error' => 'Fann ikkje avdelinga.'], 404);
            }
        }

        $current = (float)$item['stock_quantity'];
        $newStock = $type === 'in' ? $current + $quantity : $current - $quantity;

        $pdo->prepare('UPDATE lager_items SET stock_quantity = ? WHERE id = ?')
            ->execute([$newStock, (int)$item['id']]);

        $pdo->prepare(
            'INSERT INTO lager_movements
                (item_id, department_id, movement_type, quantity, stock_after, note, created_by_role)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([(int)$item['id'], $departmentId, $type, $quantity, $newStock, $note, 'public']);

        $pdo->commit();
        return [
            'ok' => true,
            'message' => $type === 'in' ? 'Vara er lagt inn.' : 'Uttaket er registrert.',
            'item' => load_item_by_id((int)$item['id']),
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function load_admin_data(array $user): array
{
    return [
        'userRole' => (string)$user['role'],
        'items' => load_items(),
        'categories' => load_categories(),
        'departments' => load_departments(false),
        'recentMovements' => load_recent_movements(),
        'recentCounts' => load_recent_counts(),
    ];
}

function load_items(): array
{
    $rows = lager_pdo()->query(
        'SELECT i.id, i.category_id, i.name, i.unit, i.stock_quantity, i.min_quantity,
                i.shelf_label, i.qr_token, i.active, c.name AS category_name
           FROM lager_items i
           JOIN lager_categories c ON c.id = i.category_id
          ORDER BY c.sort_order, i.name'
    )->fetchAll();

    return array_map('map_item', $rows);
}

function load_item_by_id(int $id): array
{
    $stmt = lager_pdo()->prepare(
        'SELECT i.id, i.category_id, i.name, i.unit, i.stock_quantity, i.min_quantity,
                i.shelf_label, i.qr_token, i.active, c.name AS category_name
           FROM lager_items i
           JOIN lager_categories c ON c.id = i.category_id
          WHERE i.id = ?
          LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        lager_json(['error' => 'Fann ikkje vara.'], 404);
    }
    return map_item($row);
}

function map_item(array $row): array
{
    $stock = (float)$row['stock_quantity'];
    $min = (float)$row['min_quantity'];
    return [
        'id' => (int)$row['id'],
        'categoryId' => (int)($row['category_id'] ?? 0),
        'category' => (string)($row['category_name'] ?? ''),
        'name' => (string)$row['name'],
        'unit' => (string)$row['unit'],
        'stockQuantity' => $stock,
        'minQuantity' => $min,
        'shelfLabel' => (string)($row['shelf_label'] ?? ''),
        'qrToken' => (string)$row['qr_token'],
        'active' => (bool)$row['active'],
        'isLow' => $min > 0 && $stock <= $min,
    ];
}

function load_categories(): array
{
    $rows = lager_pdo()->query(
        'SELECT id, name, sort_order, active FROM lager_categories ORDER BY sort_order, name'
    )->fetchAll();
    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'sortOrder' => (int)$row['sort_order'],
        'active' => (bool)$row['active'],
    ], $rows);
}

function load_departments(bool $activeOnly): array
{
    $sql = 'SELECT id, name, sort_order, active FROM lager_departments';
    if ($activeOnly) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY sort_order, name';

    $rows = lager_pdo()->query($sql)->fetchAll();
    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'sortOrder' => (int)$row['sort_order'],
        'active' => (bool)$row['active'],
    ], $rows);
}

function load_recent_movements(): array
{
    $rows = lager_pdo()->query(
        'SELECT m.id, m.movement_type, m.quantity, m.stock_after, m.note, m.created_by_role, m.created_at,
                i.name AS item_name, i.unit, d.name AS department_name
           FROM lager_movements m
           JOIN lager_items i ON i.id = m.item_id
      LEFT JOIN lager_departments d ON d.id = m.department_id
          ORDER BY m.created_at DESC, m.id DESC
          LIMIT 80'
    )->fetchAll();

    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'movementType' => (string)$row['movement_type'],
        'quantity' => (float)$row['quantity'],
        'stockAfter' => (float)$row['stock_after'],
        'note' => (string)($row['note'] ?? ''),
        'createdByRole' => (string)$row['created_by_role'],
        'createdAt' => (string)$row['created_at'],
        'itemName' => (string)$row['item_name'],
        'unit' => (string)$row['unit'],
        'department' => (string)($row['department_name'] ?? ''),
    ], $rows);
}

function load_recent_counts(): array
{
    $rows = lager_pdo()->query(
        'SELECT c.id, c.count_date, c.created_by_role, c.note, COUNT(l.id) AS line_count
           FROM lager_counts c
      LEFT JOIN lager_count_lines l ON l.count_id = c.id
          GROUP BY c.id, c.count_date, c.created_by_role, c.note
          ORDER BY c.count_date DESC, c.id DESC
          LIMIT 12'
    )->fetchAll();

    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'countDate' => (string)$row['count_date'],
        'createdByRole' => (string)$row['created_by_role'],
        'note' => (string)($row['note'] ?? ''),
        'lineCount' => (int)$row['line_count'],
    ], $rows);
}

function save_count(array $data, array $user): array
{
    $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
    if (!$lines) {
        lager_json(['error' => 'Teljinga manglar varer.'], 400);
    }

    $note = trim((string)($data['note'] ?? ''));
    if (strlen($note) > 255) {
        $note = substr($note, 0, 255);
    }

    $pdo = lager_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO lager_counts (created_by_role, note) VALUES (?, ?)')
            ->execute([(string)$user['role'], $note]);
        $countId = (int)$pdo->lastInsertId();

        $lockStmt = $pdo->prepare('SELECT id, stock_quantity FROM lager_items WHERE id = ? LIMIT 1 FOR UPDATE');
        $lineStmt = $pdo->prepare(
            'INSERT INTO lager_count_lines
                (count_id, item_id, expected_quantity, counted_quantity, difference_quantity)
             VALUES (?, ?, ?, ?, ?)'
        );
        $moveStmt = $pdo->prepare(
            'INSERT INTO lager_movements
                (item_id, department_id, movement_type, quantity, stock_after, note, created_by_role)
             VALUES (?, NULL, ?, ?, ?, ?, ?)'
        );
        $updateStmt = $pdo->prepare('UPDATE lager_items SET stock_quantity = ? WHERE id = ?');

        $savedLines = 0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $itemId = (int)($line['itemId'] ?? 0);
            if ($itemId <= 0 || !array_key_exists('countedQuantity', $line)) {
                continue;
            }

            $countedRaw = trim((string)$line['countedQuantity']);
            if ($countedRaw === '') {
                continue;
            }
            $counted = lager_parse_decimal($countedRaw, 'Talt mengd');

            $lockStmt->execute([$itemId]);
            $item = $lockStmt->fetch();
            if (!$item) {
                continue;
            }

            $expected = (float)$item['stock_quantity'];
            $diff = round($counted - $expected, 2);
            $lineStmt->execute([$countId, $itemId, $expected, $counted, $diff]);
            $updateStmt->execute([$counted, $itemId]);

            if (abs($diff) >= 0.01) {
                $moveStmt->execute([
                    $itemId,
                    'count_adjustment',
                    $diff,
                    $counted,
                    $note !== '' ? 'Vareteljing: ' . $note : 'Vareteljing',
                    (string)$user['role'],
                ]);
            }
            $savedLines++;
        }

        if ($savedLines === 0) {
            $pdo->rollBack();
            lager_json(['error' => 'Ingen talte varer vart lagra.'], 400);
        }

        $pdo->commit();
        $response = load_admin_data($user);
        $response['ok'] = true;
        $response['message'] = 'Vareteljinga er lagra.';
        $response['countId'] = $countId;
        $response['savedLines'] = $savedLines;
        return $response;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function load_report(array $data, array $user): array
{
    $from = parse_date((string)($data['from'] ?? ''), 'Frå-dato');
    $to = parse_date((string)($data['to'] ?? ''), 'Til-dato');
    if ($from > $to) {
        lager_json(['error' => 'Frå-dato kan ikkje vere etter til-dato.'], 400);
    }

    $departmentId = (int)($data['departmentId'] ?? 0);
    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $deptWhere = '';
    if ($departmentId > 0) {
        $deptWhere = ' AND m.department_id = ?';
        $params[] = $departmentId;
    }

    $sql = 'SELECT COALESCE(d.name, \'Utan avdeling\') AS department_name,
                   c.name AS category_name,
                   i.name AS item_name,
                   i.unit,
                   SUM(m.quantity) AS total_quantity,
                   COUNT(*) AS movement_count
              FROM lager_movements m
              JOIN lager_items i ON i.id = m.item_id
              JOIN lager_categories c ON c.id = i.category_id
         LEFT JOIN lager_departments d ON d.id = m.department_id
             WHERE m.movement_type = "out"
               AND m.created_at BETWEEN ? AND ?' . $deptWhere . '
             GROUP BY department_name, c.name, i.name, i.unit
             ORDER BY department_name, c.name, i.name';
    $stmt = lager_pdo()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $summarySql = 'SELECT COALESCE(d.name, \'Utan avdeling\') AS department_name,
                          COUNT(*) AS movement_count
                     FROM lager_movements m
                LEFT JOIN lager_departments d ON d.id = m.department_id
                    WHERE m.movement_type = "out"
                      AND m.created_at BETWEEN ? AND ?' . $deptWhere . '
                    GROUP BY department_name
                    ORDER BY department_name';
    $summaryStmt = lager_pdo()->prepare($summarySql);
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetchAll();

    return [
        'ok' => true,
        'from' => $from,
        'to' => $to,
        'departmentId' => $departmentId,
        'rows' => array_map(static fn(array $row): array => [
            'department' => (string)$row['department_name'],
            'category' => (string)$row['category_name'],
            'item' => (string)$row['item_name'],
            'unit' => (string)$row['unit'],
            'quantity' => (float)$row['total_quantity'],
            'movementCount' => (int)$row['movement_count'],
        ], $rows),
        'summary' => array_map(static fn(array $row): array => [
            'department' => (string)$row['department_name'],
            'movementCount' => (int)$row['movement_count'],
        ], $summary),
    ];
}

function save_item(array $data, array $user): array
{
    $itemId = (int)($data['itemId'] ?? 0);
    if ($itemId <= 0) {
        lager_json(['error' => 'Manglar vare.'], 400);
    }

    $payload = item_payload($data);
    $token = trim((string)($data['qrToken'] ?? ''));
    if ($token === '') {
        $token = ensure_unique_token(lager_slug($payload['name']), $itemId);
    } else {
        $token = ensure_unique_token(lager_slug($token), $itemId);
    }

    lager_pdo()->prepare(
        'UPDATE lager_items
            SET category_id = ?, name = ?, unit = ?, min_quantity = ?, shelf_label = ?, qr_token = ?, active = ?
          WHERE id = ?'
    )->execute([
        $payload['categoryId'],
        $payload['name'],
        $payload['unit'],
        $payload['minQuantity'],
        $payload['shelfLabel'],
        $token,
        $payload['active'] ? 1 : 0,
        $itemId,
    ]);

    $response = load_admin_data($user);
    $response['ok'] = true;
    $response['message'] = 'Vara er lagra.';
    return $response;
}

function create_item(array $data, array $user): array
{
    $payload = item_payload($data);
    $token = ensure_unique_token(lager_slug((string)($data['qrToken'] ?? $payload['name'])), null);

    lager_pdo()->prepare(
        'INSERT INTO lager_items
            (category_id, name, unit, stock_quantity, min_quantity, shelf_label, qr_token, active)
         VALUES (?, ?, ?, 0, ?, ?, ?, ?)'
    )->execute([
        $payload['categoryId'],
        $payload['name'],
        $payload['unit'],
        $payload['minQuantity'],
        $payload['shelfLabel'],
        $token,
        $payload['active'] ? 1 : 0,
    ]);

    $response = load_admin_data($user);
    $response['ok'] = true;
    $response['message'] = 'Vara er oppretta.';
    return $response;
}

function item_payload(array $data): array
{
    $categoryId = (int)($data['categoryId'] ?? 0);
    if ($categoryId <= 0) {
        lager_json(['error' => 'Vel kategori.'], 400);
    }

    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        lager_json(['error' => 'Varenamn manglar.'], 400);
    }

    $unit = trim((string)($data['unit'] ?? 'stk'));
    if ($unit === '') {
        $unit = 'stk';
    }

    $shelfLabel = trim((string)($data['shelfLabel'] ?? ''));
    if (strlen($shelfLabel) > 80) {
        $shelfLabel = substr($shelfLabel, 0, 80);
    }

    return [
        'categoryId' => $categoryId,
        'name' => $name,
        'unit' => substr($unit, 0, 40),
        'minQuantity' => lager_parse_decimal($data['minQuantity'] ?? 0, 'Min-nivå'),
        'shelfLabel' => $shelfLabel,
        'active' => !empty($data['active']),
    ];
}

function save_department(array $data, array $user): array
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        lager_json(['error' => 'Avdelingsnamn manglar.'], 400);
    }

    $departmentId = (int)($data['departmentId'] ?? 0);
    $sortOrder = (int)($data['sortOrder'] ?? 100);
    $active = !empty($data['active']) ? 1 : 0;

    if ($departmentId > 0) {
        lager_pdo()->prepare(
            'UPDATE lager_departments SET name = ?, sort_order = ?, active = ? WHERE id = ?'
        )->execute([$name, $sortOrder, $active, $departmentId]);
    } else {
        lager_pdo()->prepare(
            'INSERT INTO lager_departments (name, sort_order, active) VALUES (?, ?, ?)'
        )->execute([$name, $sortOrder, $active]);
    }

    $response = load_admin_data($user);
    $response['ok'] = true;
    $response['message'] = 'Avdelinga er lagra.';
    return $response;
}

function ensure_unique_token(string $baseToken, ?int $currentItemId): string
{
    $baseToken = lager_slug($baseToken);
    $candidate = $baseToken;
    $n = 2;
    $stmt = lager_pdo()->prepare(
        'SELECT id FROM lager_items WHERE qr_token = ? AND (? IS NULL OR id <> ?) LIMIT 1'
    );

    while (true) {
        $stmt->execute([$candidate, $currentItemId, $currentItemId]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $candidate = $baseToken . '-' . $n;
        $n++;
    }
}

function parse_date(string $value, string $label): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        lager_json(['error' => $label . ' er ugyldig.'], 400);
    }
    return $dt->format('Y-m-d');
}
