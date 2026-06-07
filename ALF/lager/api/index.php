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
        lager_destroy_session();
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

    if ($action === 'save_category_contact') {
        $user = lager_require_role(['laerar']);
        lager_json(save_category_contact($data, $user));
    }

    if ($action === 'test_purchase_alert') {
        $user = lager_require_role(['laerar']);
        lager_json(send_test_purchase_alert($data, $user));
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

    session_regenerate_id(true);
    $now = time();
    $_SESSION['lager_user'] = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
    ];
    $_SESSION['lager_login_at'] = $now;
    $_SESSION['lager_last_seen_at'] = $now;
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

        $itemId = (int)$item['id'];
        $pdo->commit();
        safe_process_low_stock_for_items([$itemId], 'public_movement', 'public');
        return [
            'ok' => true,
            'message' => $type === 'in' ? 'Vara er lagt inn.' : 'Uttaket er registrert.',
            'item' => load_item_by_id($itemId),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function load_admin_data(array $user): array
{
    $includeContacts = (string)$user['role'] === 'laerar';
    return [
        'userRole' => (string)$user['role'],
        'items' => load_items($includeContacts),
        'categories' => load_categories($includeContacts),
        'departments' => load_departments(false),
        'recentMovements' => load_recent_movements(),
        'recentCounts' => load_recent_counts(),
        'recentEmailLogs' => $includeContacts ? load_recent_email_logs() : [],
        'mailEnabled' => $includeContacts ? !empty(lager_config()['mail_enabled']) : false,
    ];
}

function load_items(bool $includeContacts = false): array
{
    $rows = lager_pdo()->query(
        'SELECT i.id, i.category_id, i.name, i.unit, i.stock_quantity, i.min_quantity,
                i.shelf_label, i.qr_token, i.purchase_contact_name, i.purchase_contact_email,
                i.low_stock_notified_at, i.low_stock_notified_quantity, i.active,
                c.name AS category_name, c.purchase_contact_name AS category_contact_name,
                c.purchase_contact_email AS category_contact_email
           FROM lager_items i
           JOIN lager_categories c ON c.id = i.category_id
          ORDER BY c.sort_order, i.name'
    )->fetchAll();

    return array_map(static fn(array $row): array => map_item($row, $includeContacts), $rows);
}

function load_item_by_id(int $id, bool $includeContacts = false): array
{
    $stmt = lager_pdo()->prepare(
        'SELECT i.id, i.category_id, i.name, i.unit, i.stock_quantity, i.min_quantity,
                i.shelf_label, i.qr_token, i.purchase_contact_name, i.purchase_contact_email,
                i.low_stock_notified_at, i.low_stock_notified_quantity, i.active,
                c.name AS category_name, c.purchase_contact_name AS category_contact_name,
                c.purchase_contact_email AS category_contact_email
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
    return map_item($row, $includeContacts);
}

function map_item(array $row, bool $includeContacts = false): array
{
    $stock = (float)$row['stock_quantity'];
    $min = (float)$row['min_quantity'];
    $item = [
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

    if ($includeContacts) {
        $itemContactEmail = (string)($row['purchase_contact_email'] ?? '');
        $itemContactName = (string)($row['purchase_contact_name'] ?? '');
        $categoryContactEmail = (string)($row['category_contact_email'] ?? '');
        $categoryContactName = (string)($row['category_contact_name'] ?? '');
        $usesItemContact = $itemContactEmail !== '';

        $item['purchaseContactName'] = $itemContactName;
        $item['purchaseContactEmail'] = $itemContactEmail;
        $item['categoryContactName'] = $categoryContactName;
        $item['categoryContactEmail'] = $categoryContactEmail;
        $item['effectivePurchaseContactName'] = $usesItemContact ? $itemContactName : $categoryContactName;
        $item['effectivePurchaseContactEmail'] = $usesItemContact ? $itemContactEmail : $categoryContactEmail;
        $item['lowStockNotifiedAt'] = $row['low_stock_notified_at'] ?? null;
        $item['lowStockNotifiedQuantity'] = isset($row['low_stock_notified_quantity'])
            ? (float)$row['low_stock_notified_quantity']
            : null;
    }

    return $item;
}

function load_categories(bool $includeContacts = false): array
{
    $rows = lager_pdo()->query(
        'SELECT id, name, sort_order, active, purchase_contact_name, purchase_contact_email
           FROM lager_categories
          ORDER BY sort_order, name'
    )->fetchAll();
    return array_map(static function (array $row) use ($includeContacts): array {
        $category = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'sortOrder' => (int)$row['sort_order'],
            'active' => (bool)$row['active'],
        ];
        if ($includeContacts) {
            $category['purchaseContactName'] = (string)($row['purchase_contact_name'] ?? '');
            $category['purchaseContactEmail'] = (string)($row['purchase_contact_email'] ?? '');
        }
        return $category;
    }, $rows);
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
        'SELECT m.id, m.item_id, m.movement_type, m.quantity, m.stock_after, m.note, m.created_by_role, m.created_at,
                i.name AS item_name, i.unit, d.name AS department_name
           FROM lager_movements m
           JOIN lager_items i ON i.id = m.item_id
      LEFT JOIN lager_departments d ON d.id = m.department_id
          ORDER BY m.created_at DESC, m.id DESC
          LIMIT 80'
    )->fetchAll();

    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'itemId' => (int)$row['item_id'],
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

function load_recent_email_logs(): array
{
    $rows = lager_pdo()->query(
        'SELECT l.id, l.event_type, l.status, l.recipient_name, l.recipient_email,
                l.subject, l.error_message, l.created_by_role, l.created_at,
                i.name AS item_name, c.name AS category_name
           FROM lager_email_log l
      LEFT JOIN lager_items i ON i.id = l.item_id
      LEFT JOIN lager_categories c ON c.id = l.category_id
          ORDER BY l.created_at DESC, l.id DESC
          LIMIT 30'
    )->fetchAll();

    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'eventType' => (string)$row['event_type'],
        'status' => (string)$row['status'],
        'recipientName' => (string)($row['recipient_name'] ?? ''),
        'recipientEmail' => (string)($row['recipient_email'] ?? ''),
        'subject' => (string)($row['subject'] ?? ''),
        'errorMessage' => (string)($row['error_message'] ?? ''),
        'createdByRole' => (string)$row['created_by_role'],
        'createdAt' => (string)$row['created_at'],
        'itemName' => (string)($row['item_name'] ?? ''),
        'categoryName' => (string)($row['category_name'] ?? ''),
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
        $touchedItemIds = [];
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
            $touchedItemIds[] = $itemId;

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
        safe_process_low_stock_for_items($touchedItemIds, 'count', (string)$user['role']);
        $response = load_admin_data($user);
        $response['ok'] = true;
        $response['message'] = 'Vareteljinga er lagra.';
        $response['countId'] = $countId;
        $response['savedLines'] = $savedLines;
        return $response;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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

    $oldItem = load_item_private_row($itemId);
    $payload = item_payload($data);
    $token = trim((string)($data['qrToken'] ?? ''));
    if ($token === '') {
        $token = ensure_unique_token(lager_slug($payload['name']), $itemId);
    } else {
        $token = ensure_unique_token(lager_slug($token), $itemId);
    }

    lager_pdo()->prepare(
        'UPDATE lager_items
            SET category_id = ?, name = ?, unit = ?, min_quantity = ?, shelf_label = ?,
                qr_token = ?, purchase_contact_name = ?, purchase_contact_email = ?, active = ?
          WHERE id = ?'
    )->execute([
        $payload['categoryId'],
        $payload['name'],
        $payload['unit'],
        $payload['minQuantity'],
        $payload['shelfLabel'],
        $token,
        $payload['purchaseContactName'],
        $payload['purchaseContactEmail'],
        $payload['active'] ? 1 : 0,
        $itemId,
    ]);

    if (item_alert_context_changed($oldItem, $payload)) {
        reset_low_stock_notification([$itemId]);
        safe_process_low_stock_for_items([$itemId], 'item_saved', (string)$user['role']);
    }

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
            (category_id, name, unit, stock_quantity, min_quantity, shelf_label,
             qr_token, purchase_contact_name, purchase_contact_email, active)
         VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $payload['categoryId'],
        $payload['name'],
        $payload['unit'],
        $payload['minQuantity'],
        $payload['shelfLabel'],
        $token,
        $payload['purchaseContactName'],
        $payload['purchaseContactEmail'],
        $payload['active'] ? 1 : 0,
    ]);
    $itemId = (int)lager_pdo()->lastInsertId();
    safe_process_low_stock_for_items([$itemId], 'item_created', (string)$user['role']);

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
        'purchaseContactName' => trim_length((string)($data['purchaseContactName'] ?? ''), 160),
        'purchaseContactEmail' => normalize_optional_email((string)($data['purchaseContactEmail'] ?? ''), 'E-post for vareansvarleg'),
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

function save_category_contact(array $data, array $user): array
{
    $categoryId = (int)($data['categoryId'] ?? 0);
    if ($categoryId <= 0) {
        lager_json(['error' => 'Manglar kategori.'], 400);
    }

    $name = trim_length((string)($data['purchaseContactName'] ?? ''), 160);
    $email = normalize_optional_email((string)($data['purchaseContactEmail'] ?? ''), 'E-post for innkjøpsansvarleg');
    $oldCategory = load_category_private_row($categoryId);
    $changed = (string)($oldCategory['purchase_contact_name'] ?? '') !== $name
        || (string)($oldCategory['purchase_contact_email'] ?? '') !== $email;

    $stmt = lager_pdo()->prepare(
        'UPDATE lager_categories
            SET purchase_contact_name = ?, purchase_contact_email = ?
          WHERE id = ?'
    );
    $stmt->execute([$name, $email, $categoryId]);

    if ($changed) {
        $itemIds = load_category_default_contact_low_item_ids($categoryId);
        reset_low_stock_notification($itemIds);
        safe_process_low_stock_for_items($itemIds, 'category_contact_saved', (string)$user['role']);
    }

    $response = load_admin_data($user);
    $response['ok'] = true;
    $response['message'] = 'Innkjøpsansvarleg er lagra.';
    return $response;
}

function send_test_purchase_alert(array $data, array $user): array
{
    $categoryId = (int)($data['categoryId'] ?? 0);
    if ($categoryId <= 0) {
        lager_json(['error' => 'Manglar kategori.'], 400);
    }

    $category = load_category_private_row($categoryId);
    $recipientName = (string)($category['purchase_contact_name'] ?? '');
    $recipientEmail = (string)($category['purchase_contact_email'] ?? '');
    $subject = 'Testvarsel frå tørrvarelageret';
    $body = implode("\n", [
        'Dette er ein test av automatisk innkjøpsvarsel frå SkuleTech Tørrvarelager.',
        '',
        'Kategori: ' . (string)$category['name'],
        'Når ei vare i denne kategorien kjem på eller under minimumsnivå, blir varselet sendt til denne adressa.',
        '',
        'Dette er berre ein test. Ingen lagerstatus er endra.',
    ]);

    $result = send_lager_mail($recipientEmail, $recipientName, $subject, $body);
    log_email_event(
        null,
        $categoryId,
        'test',
        $result['status'],
        $recipientName,
        $recipientEmail,
        $subject,
        $body,
        $result['error'],
        (string)$user['role']
    );

    $response = load_admin_data($user);
    $response['ok'] = true;
    $response['sent'] = $result['status'] === 'sent';
    $response['message'] = $result['status'] === 'sent'
        ? 'Testvarselet er sendt.'
        : 'Testvarselet vart ikkje sendt: ' . $result['error'];
    return $response;
}

function load_item_private_row(int $itemId): array
{
    $stmt = lager_pdo()->prepare(
        'SELECT i.id, i.category_id, i.name, i.unit, i.stock_quantity, i.min_quantity,
                i.shelf_label, i.purchase_contact_name, i.purchase_contact_email,
                i.low_stock_notified_at, i.active, c.name AS category_name,
                c.purchase_contact_name AS category_contact_name,
                c.purchase_contact_email AS category_contact_email
           FROM lager_items i
           JOIN lager_categories c ON c.id = i.category_id
          WHERE i.id = ?
          LIMIT 1'
    );
    $stmt->execute([$itemId]);
    $row = $stmt->fetch();
    if (!$row) {
        lager_json(['error' => 'Fann ikkje vara.'], 404);
    }
    return $row;
}

function load_category_private_row(int $categoryId): array
{
    $stmt = lager_pdo()->prepare(
        'SELECT id, name, purchase_contact_name, purchase_contact_email
           FROM lager_categories
          WHERE id = ?
          LIMIT 1'
    );
    $stmt->execute([$categoryId]);
    $row = $stmt->fetch();
    if (!$row) {
        lager_json(['error' => 'Fann ikkje kategorien.'], 404);
    }
    return $row;
}

function load_category_default_contact_low_item_ids(int $categoryId): array
{
    $stmt = lager_pdo()->prepare(
        'SELECT id
           FROM lager_items
          WHERE category_id = ?
            AND purchase_contact_email = ""
            AND active = 1
            AND min_quantity > 0
            AND stock_quantity <= min_quantity'
    );
    $stmt->execute([$categoryId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

function item_alert_context_changed(array $oldItem, array $payload): bool
{
    return (int)$oldItem['category_id'] !== (int)$payload['categoryId']
        || abs((float)$oldItem['min_quantity'] - (float)$payload['minQuantity']) >= 0.01
        || (string)($oldItem['purchase_contact_name'] ?? '') !== $payload['purchaseContactName']
        || (string)($oldItem['purchase_contact_email'] ?? '') !== $payload['purchaseContactEmail']
        || (bool)$oldItem['active'] !== (bool)$payload['active'];
}

function safe_process_low_stock_for_items(array $itemIds, string $source, string $role): void
{
    try {
        process_low_stock_for_items($itemIds, $source, $role);
    } catch (Throwable $e) {
        error_log('Lager low-stock notification failed: ' . $e->getMessage());
    }
}

function process_low_stock_for_items(array $itemIds, string $source, string $role): void
{
    $itemIds = normalize_ids($itemIds);
    if (!$itemIds) {
        return;
    }

    reset_recovered_low_stock_items($itemIds);

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = lager_pdo()->prepare(
        'SELECT i.id, i.category_id, i.name, i.unit, i.stock_quantity, i.min_quantity,
                i.shelf_label, i.purchase_contact_name, i.purchase_contact_email,
                c.name AS category_name, c.purchase_contact_name AS category_contact_name,
                c.purchase_contact_email AS category_contact_email
           FROM lager_items i
           JOIN lager_categories c ON c.id = i.category_id
          WHERE i.id IN (' . $placeholders . ')
            AND i.active = 1
            AND i.min_quantity > 0
            AND i.stock_quantity <= i.min_quantity
            AND i.low_stock_notified_at IS NULL
          ORDER BY c.name, i.name'
    );
    $stmt->execute($itemIds);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return;
    }

    $groups = [];
    foreach ($rows as $row) {
        $recipient = purchase_recipient_for_item($row);
        if ($recipient['email'] === '' || !filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
            $error = $recipient['email'] === ''
                ? 'Manglar e-postadresse for innkjøpsansvarleg.'
                : 'Ugyldig e-postadresse for innkjøpsansvarleg.';
            log_email_event(
                (int)$row['id'],
                (int)$row['category_id'],
                'low_stock',
                'skipped',
                $recipient['name'],
                $recipient['email'],
                'Innkjøpsvarsel vart ikkje sendt',
                low_stock_body([$row], $recipient['name'], $source),
                $error,
                $role
            );
            mark_low_stock_notified([(int)$row['id']]);
            continue;
        }

        $key = strtolower($recipient['email']);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'name' => $recipient['name'],
                'email' => $recipient['email'],
                'items' => [],
            ];
        }
        $groups[$key]['items'][] = $row;
    }

    foreach ($groups as $group) {
        $subject = low_stock_subject($group['items']);
        $body = low_stock_body($group['items'], $group['name'], $source);
        $result = send_lager_mail($group['email'], $group['name'], $subject, $body);
        $idsToMark = [];

        foreach ($group['items'] as $row) {
            log_email_event(
                (int)$row['id'],
                (int)$row['category_id'],
                'low_stock',
                $result['status'],
                $group['name'],
                $group['email'],
                $subject,
                $body,
                $result['error'],
                $role
            );
            if ($result['status'] !== 'skipped') {
                $idsToMark[] = (int)$row['id'];
            }
        }

        mark_low_stock_notified($idsToMark);
    }
}

function reset_recovered_low_stock_items(array $itemIds): void
{
    $itemIds = normalize_ids($itemIds);
    if (!$itemIds) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    lager_pdo()->prepare(
        'UPDATE lager_items
            SET low_stock_notified_at = NULL, low_stock_notified_quantity = NULL
          WHERE id IN (' . $placeholders . ')
            AND (active = 0 OR min_quantity <= 0 OR stock_quantity > min_quantity)'
    )->execute($itemIds);
}

function reset_low_stock_notification(array $itemIds): void
{
    $itemIds = normalize_ids($itemIds);
    if (!$itemIds) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    lager_pdo()->prepare(
        'UPDATE lager_items
            SET low_stock_notified_at = NULL, low_stock_notified_quantity = NULL
          WHERE id IN (' . $placeholders . ')'
    )->execute($itemIds);
}

function mark_low_stock_notified(array $itemIds): void
{
    $itemIds = normalize_ids($itemIds);
    if (!$itemIds) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    lager_pdo()->prepare(
        'UPDATE lager_items
            SET low_stock_notified_at = CURRENT_TIMESTAMP,
                low_stock_notified_quantity = stock_quantity
          WHERE id IN (' . $placeholders . ')'
    )->execute($itemIds);
}

function purchase_recipient_for_item(array $row): array
{
    $itemEmail = trim((string)($row['purchase_contact_email'] ?? ''));
    if ($itemEmail !== '') {
        return [
            'name' => (string)($row['purchase_contact_name'] ?? ''),
            'email' => $itemEmail,
        ];
    }

    return [
        'name' => (string)($row['category_contact_name'] ?? ''),
        'email' => trim((string)($row['category_contact_email'] ?? '')),
    ];
}

function low_stock_subject(array $items): string
{
    if (count($items) === 1) {
        return 'Innkjøpsvarsel: ' . (string)$items[0]['name'];
    }
    return 'Innkjøpsvarsel: ' . count($items) . ' varer på minimumsnivå';
}

function low_stock_body(array $items, string $recipientName, string $source): string
{
    $config = lager_config();
    $lines = [];
    $lines[] = trim($recipientName) !== '' ? 'Hei ' . trim($recipientName) . ',' : 'Hei,';
    $lines[] = '';
    $lines[] = 'Dette er eit automatisk innkjøpsvarsel frå SkuleTech Tørrvarelager.';
    $lines[] = 'Følgjande varer er på eller under minimumsnivå:';
    $lines[] = '';

    foreach ($items as $item) {
        $shelf = trim((string)($item['shelf_label'] ?? ''));
        $line = '- ' . (string)$item['name'] . ' (' . (string)$item['category_name'] . '): '
            . lager_format_qty((float)$item['stock_quantity']) . ' ' . (string)$item['unit']
            . ' på lager, min ' . lager_format_qty((float)$item['min_quantity']) . ' ' . (string)$item['unit'];
        if ($shelf !== '') {
            $line .= ', hylle ' . $shelf;
        }
        $lines[] = $line;
    }

    $appUrl = trim((string)($config['app_url'] ?? ''));
    if ($appUrl !== '') {
        $lines[] = '';
        $lines[] = 'Opne lagerdashbordet: ' . $appUrl;
    }

    $lines[] = '';
    $lines[] = 'Det blir ikkje sendt nytt automatisk varsel for same låg-lager-periode før vara er fylt over minimumsnivå igjen.';
    $lines[] = 'Kjelde: ' . low_stock_source_label($source);
    return implode("\n", $lines);
}

function low_stock_source_label(string $source): string
{
    return [
        'public_movement' => 'QR-registrering',
        'count' => 'vareteljing',
        'item_saved' => 'vareadministrasjon',
        'item_created' => 'ny vare',
        'category_contact_saved' => 'innkjøpsansvarleg endra',
    ][$source] ?? 'lageroppdatering';
}

function send_lager_mail(string $toEmail, string $toName, string $subject, string $body): array
{
    $config = lager_config();
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 'skipped', 'error' => 'Mottakar manglar eller har ugyldig e-postadresse.'];
    }

    if (empty($config['mail_enabled'])) {
        return ['status' => 'skipped', 'error' => 'E-postsending er ikkje slått på i config.local.php.'];
    }

    if (!function_exists('mail')) {
        return ['status' => 'failed', 'error' => 'PHP mail() er ikkje tilgjengeleg på serveren.'];
    }

    $fromEmail = trim((string)($config['mail_from'] ?? ''));
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 'failed', 'error' => 'Avsendaradresse manglar eller er ugyldig.'];
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . mail_address_header($fromEmail, (string)($config['mail_from_name'] ?? '')),
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    $replyTo = trim((string)($config['mail_reply_to'] ?? ''));
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . mail_address_header($replyTo, '');
    }

    $params = '-f ' . (function_exists('escapeshellarg') ? escapeshellarg($fromEmail) : $fromEmail);
    $sent = @mail(
        $toEmail,
        mime_header($subject),
        str_replace("\n", "\r\n", $body),
        implode("\r\n", $headers),
        $params
    );

    return $sent
        ? ['status' => 'sent', 'error' => '']
        : ['status' => 'failed', 'error' => 'Serveren returnerte feil frå mail().'];
}

function log_email_event(
    ?int $itemId,
    ?int $categoryId,
    string $eventType,
    string $status,
    string $recipientName,
    string $recipientEmail,
    string $subject,
    string $message,
    string $error,
    string $role
): void {
    lager_pdo()->prepare(
        'INSERT INTO lager_email_log
            (item_id, category_id, event_type, status, recipient_name, recipient_email,
             subject, message, error_message, created_by_role)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $itemId,
        $categoryId,
        $eventType,
        $status,
        trim_length($recipientName, 160),
        trim_length($recipientEmail, 190),
        trim_length($subject, 255),
        $message,
        trim_length($error, 255),
        in_array($role, ['system', 'public', 'driftsleiar', 'laerar'], true) ? $role : 'system',
    ]);
}

function normalize_ids(array $ids): array
{
    return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
}

function normalize_optional_email(string $email, string $label): string
{
    $email = trim_length($email, 190);
    if ($email === '') {
        return '';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        lager_json(['error' => $label . ' er ugyldig.'], 400);
    }
    return $email;
}

function trim_length(string $value, int $maxLength): string
{
    $value = trim((string)preg_replace('/[\r\n]+/u', ' ', $value));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

function mail_address_header(string $email, string $name): string
{
    $name = trim_length($name, 120);
    if ($name === '') {
        return $email;
    }
    return mime_header($name) . ' <' . $email . '>';
}

function mime_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode(trim_length($value, 255)) . '?=';
}

function lager_format_qty(float $value): string
{
    $formatted = number_format($value, 2, ',', ' ');
    return rtrim(rtrim($formatted, '0'), ',');
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
