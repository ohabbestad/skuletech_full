<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $data = utstyr_request();
    $action = (string)($data['action'] ?? 'dashboard_load');

    utstyr_start_session();

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        utstyr_json(['ok' => true]);
    }

    if ($action === 'login') {
        $user = login_with_password((string)($data['role'] ?? ''), (string)($data['password'] ?? ''));
        $response = load_dashboard_data($user);
        $response['ok'] = true;
        $response['message'] = 'Du er logga inn.';
        utstyr_json($response);
    }

    if (!empty($data['password'])) {
        login_with_password((string)($data['role'] ?? ''), (string)$data['password']);
    }

    if ($action === 'dashboard_load') {
        $user = utstyr_require_role(['tilsett', 'admin']);
        ensure_requested_role($data, $user);
        utstyr_json(load_dashboard_data($user));
    }

    if ($action === 'item_load') {
        $user = utstyr_require_role(['tilsett', 'admin']);
        utstyr_json(load_item_context((string)($data['token'] ?? ''), $user));
    }

    if ($action === 'create_loan') {
        $user = utstyr_require_role(['tilsett', 'admin']);
        utstyr_json(create_loan($data, $user));
    }

    if ($action === 'return_loan') {
        $user = utstyr_require_role(['tilsett', 'admin']);
        utstyr_json(return_loan($data, $user));
    }

    if ($action === 'admin_report') {
        $user = utstyr_require_role(['admin']);
        utstyr_json(load_admin_report($data, $user));
    }

    if ($action === 'admin_create_item') {
        $user = utstyr_require_role(['admin']);
        utstyr_json(create_item($data, $user));
    }

    if ($action === 'admin_save_item') {
        $user = utstyr_require_role(['admin']);
        utstyr_json(save_item($data, $user));
    }

    if ($action === 'admin_save_category') {
        $user = utstyr_require_role(['admin']);
        utstyr_json(save_category($data, $user));
    }

    if ($action === 'admin_save_location') {
        $user = utstyr_require_role(['admin']);
        utstyr_json(save_location($data, $user));
    }

    utstyr_json(['error' => 'Ukjend handling.'], 400);
} catch (Throwable $e) {
    utstyr_json(['error' => 'Serverfeil: ' . $e->getMessage()], 500);
}

function login_with_password(string $role, string $password): array
{
    if (!in_array($role, ['tilsett', 'admin'], true)) {
        utstyr_json(['error' => 'Ukjend rolle.'], 400);
    }

    if ($password === '') {
        utstyr_json(['error' => 'Skriv inn passord.'], 400);
    }

    $stmt = utstyr_pdo()->prepare(
        'SELECT id, username, role, password_hash FROM utstyr_users WHERE role = ? AND active = 1 LIMIT 1'
    );
    $stmt->execute([$role]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        utstyr_json(['error' => 'Feil passord.'], 401);
    }

    $_SESSION['utstyr_user'] = [
        'id' => (int)$user['id'],
        'username' => (string)$user['username'],
        'role' => (string)$user['role'],
    ];

    return $_SESSION['utstyr_user'];
}

function ensure_requested_role(array $data, array $user): void
{
    $requestedRole = (string)($data['role'] ?? ($user['role'] ?? ''));
    if ($requestedRole !== '' && ($user['role'] ?? '') !== $requestedRole && ($user['role'] ?? '') !== 'admin') {
        utstyr_json(['error' => 'Du må logge inn med riktig rolle.'], 403);
    }
}

function load_dashboard_data(array $user): array
{
    $response = [
        'userRole' => (string)$user['role'],
        'items' => load_items(),
        'categories' => load_categories(false),
        'locations' => load_locations(false),
        'activeLoans' => load_active_loans(null),
    ];

    if (($user['role'] ?? '') === 'admin') {
        $response['recentLoans'] = load_recent_loans();
    }

    return $response;
}

function load_item_context(string $token, array $user): array
{
    $token = trim($token);
    if ($token === '') {
        utstyr_json(['error' => 'Manglar utstyrskode.'], 400);
    }

    $item = load_item_by_token($token);
    return [
        'userRole' => (string)$user['role'],
        'item' => $item,
        'activeLoans' => load_active_loans((int)$item['id']),
    ];
}

function load_items(): array
{
    $rows = utstyr_pdo()->query(
        'SELECT i.id, i.category_id, i.location_id, i.name, i.description, i.loan_mode,
                i.total_quantity, i.default_loan_days, i.qr_token, i.active,
                c.name AS category_name, l.name AS location_name,
                COALESCE(a.active_quantity, 0) AS active_quantity,
                COALESCE(o.overdue_quantity, 0) AS overdue_quantity
           FROM utstyr_items i
           JOIN utstyr_categories c ON c.id = i.category_id
      LEFT JOIN utstyr_locations l ON l.id = i.location_id
      LEFT JOIN (
                SELECT item_id, SUM(quantity) AS active_quantity
                  FROM utstyr_loans
                 WHERE returned_at IS NULL
                 GROUP BY item_id
           ) a ON a.item_id = i.id
      LEFT JOIN (
                SELECT item_id, SUM(quantity) AS overdue_quantity
                  FROM utstyr_loans
                 WHERE returned_at IS NULL
                   AND expected_return_date < CURRENT_DATE
                 GROUP BY item_id
           ) o ON o.item_id = i.id
          ORDER BY c.sort_order, i.name'
    )->fetchAll();

    return array_map('map_item', $rows);
}

function load_item_by_token(string $token): array
{
    $stmt = utstyr_pdo()->prepare(
        'SELECT i.id, i.category_id, i.location_id, i.name, i.description, i.loan_mode,
                i.total_quantity, i.default_loan_days, i.qr_token, i.active,
                c.name AS category_name, l.name AS location_name,
                COALESCE(a.active_quantity, 0) AS active_quantity,
                COALESCE(o.overdue_quantity, 0) AS overdue_quantity
           FROM utstyr_items i
           JOIN utstyr_categories c ON c.id = i.category_id
      LEFT JOIN utstyr_locations l ON l.id = i.location_id
      LEFT JOIN (
                SELECT item_id, SUM(quantity) AS active_quantity
                  FROM utstyr_loans
                 WHERE returned_at IS NULL
                 GROUP BY item_id
           ) a ON a.item_id = i.id
      LEFT JOIN (
                SELECT item_id, SUM(quantity) AS overdue_quantity
                  FROM utstyr_loans
                 WHERE returned_at IS NULL
                   AND expected_return_date < CURRENT_DATE
                 GROUP BY item_id
           ) o ON o.item_id = i.id
          WHERE i.qr_token = ? AND i.active = 1
          LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        utstyr_json(['error' => 'Fann ikkje utstyret.'], 404);
    }
    return map_item($row);
}

function load_item_by_id(int $id): array
{
    $stmt = utstyr_pdo()->prepare(
        'SELECT i.id, i.category_id, i.location_id, i.name, i.description, i.loan_mode,
                i.total_quantity, i.default_loan_days, i.qr_token, i.active,
                c.name AS category_name, l.name AS location_name,
                COALESCE(a.active_quantity, 0) AS active_quantity,
                COALESCE(o.overdue_quantity, 0) AS overdue_quantity
           FROM utstyr_items i
           JOIN utstyr_categories c ON c.id = i.category_id
      LEFT JOIN utstyr_locations l ON l.id = i.location_id
      LEFT JOIN (
                SELECT item_id, SUM(quantity) AS active_quantity
                  FROM utstyr_loans
                 WHERE returned_at IS NULL
                 GROUP BY item_id
           ) a ON a.item_id = i.id
      LEFT JOIN (
                SELECT item_id, SUM(quantity) AS overdue_quantity
                  FROM utstyr_loans
                 WHERE returned_at IS NULL
                   AND expected_return_date < CURRENT_DATE
                 GROUP BY item_id
           ) o ON o.item_id = i.id
          WHERE i.id = ?
          LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        utstyr_json(['error' => 'Fann ikkje utstyret.'], 404);
    }
    return map_item($row);
}

function map_item(array $row): array
{
    $total = max(1, (int)$row['total_quantity']);
    $active = max(0, (int)$row['active_quantity']);
    $available = max(0, $total - $active);
    $mode = (string)$row['loan_mode'];

    return [
        'id' => (int)$row['id'],
        'categoryId' => (int)$row['category_id'],
        'locationId' => $row['location_id'] !== null ? (int)$row['location_id'] : null,
        'category' => (string)$row['category_name'],
        'location' => (string)($row['location_name'] ?? ''),
        'name' => (string)$row['name'],
        'description' => (string)($row['description'] ?? ''),
        'loanMode' => $mode,
        'totalQuantity' => $mode === 'unique' ? 1 : $total,
        'activeQuantity' => $active,
        'availableQuantity' => $available,
        'overdueQuantity' => max(0, (int)$row['overdue_quantity']),
        'defaultLoanDays' => max(1, (int)$row['default_loan_days']),
        'qrToken' => (string)$row['qr_token'],
        'active' => (bool)$row['active'],
        'isAvailable' => (bool)$row['active'] && $available > 0,
        'hasOverdue' => (int)$row['overdue_quantity'] > 0,
    ];
}

function load_categories(bool $activeOnly): array
{
    $sql = 'SELECT id, name, sort_order, active FROM utstyr_categories';
    if ($activeOnly) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY sort_order, name';

    $rows = utstyr_pdo()->query($sql)->fetchAll();
    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'sortOrder' => (int)$row['sort_order'],
        'active' => (bool)$row['active'],
    ], $rows);
}

function load_locations(bool $activeOnly): array
{
    $sql = 'SELECT id, name, sort_order, active FROM utstyr_locations';
    if ($activeOnly) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY sort_order, name';

    $rows = utstyr_pdo()->query($sql)->fetchAll();
    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'sortOrder' => (int)$row['sort_order'],
        'active' => (bool)$row['active'],
    ], $rows);
}

function load_active_loans(?int $itemId): array
{
    $where = 'WHERE lo.returned_at IS NULL';
    $params = [];
    if ($itemId !== null) {
        $where .= ' AND lo.item_id = ?';
        $params[] = $itemId;
    }

    $stmt = utstyr_pdo()->prepare(
        'SELECT lo.id, lo.item_id, lo.borrower_name, lo.quantity, lo.expected_return_date,
                lo.borrowed_at, lo.note, lo.created_by_role,
                i.name AS item_name, i.loan_mode, i.qr_token, c.name AS category_name, l.name AS location_name
           FROM utstyr_loans lo
           JOIN utstyr_items i ON i.id = lo.item_id
           JOIN utstyr_categories c ON c.id = i.category_id
      LEFT JOIN utstyr_locations l ON l.id = i.location_id
          ' . $where . '
          ORDER BY lo.expected_return_date ASC, lo.borrowed_at DESC, lo.id DESC'
    );
    $stmt->execute($params);
    return array_map('map_active_loan', $stmt->fetchAll());
}

function load_recent_loans(): array
{
    $rows = utstyr_pdo()->query(
        'SELECT lo.id, lo.item_id, lo.borrower_name, lo.quantity, lo.expected_return_date,
                lo.borrowed_at, lo.returned_at, lo.returned_by_name, lo.note, lo.return_note,
                lo.created_by_role, lo.returned_by_role,
                i.name AS item_name, i.loan_mode, i.qr_token, c.name AS category_name, l.name AS location_name
           FROM utstyr_loans lo
           JOIN utstyr_items i ON i.id = lo.item_id
           JOIN utstyr_categories c ON c.id = i.category_id
      LEFT JOIN utstyr_locations l ON l.id = i.location_id
          ORDER BY lo.borrowed_at DESC, lo.id DESC
          LIMIT 80'
    )->fetchAll();

    return array_map('map_history_loan', $rows);
}

function map_active_loan(array $row): array
{
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    return [
        'id' => (int)$row['id'],
        'itemId' => (int)$row['item_id'],
        'itemName' => (string)$row['item_name'],
        'category' => (string)$row['category_name'],
        'location' => (string)($row['location_name'] ?? ''),
        'loanMode' => (string)$row['loan_mode'],
        'qrToken' => (string)$row['qr_token'],
        'borrowerName' => (string)$row['borrower_name'],
        'quantity' => (int)$row['quantity'],
        'expectedReturnDate' => (string)$row['expected_return_date'],
        'borrowedAt' => (string)$row['borrowed_at'],
        'note' => (string)($row['note'] ?? ''),
        'createdByRole' => (string)$row['created_by_role'],
        'isOverdue' => (string)$row['expected_return_date'] < $today,
    ];
}

function map_history_loan(array $row): array
{
    $loan = map_active_loan($row);
    $loan['returnedAt'] = $row['returned_at'] !== null ? (string)$row['returned_at'] : '';
    $loan['returnedByName'] = (string)($row['returned_by_name'] ?? '');
    $loan['returnNote'] = (string)($row['return_note'] ?? '');
    $loan['returnedByRole'] = (string)($row['returned_by_role'] ?? '');
    $loan['isReturned'] = $row['returned_at'] !== null;
    return $loan;
}

function create_loan(array $data, array $user): array
{
    $itemId = resolve_item_id($data);
    $borrowerName = utstyr_clean_text($data['borrowerName'] ?? '', 120);
    if ($borrowerName === '') {
        utstyr_json(['error' => 'Skriv inn namn på lånar.'], 400);
    }

    $expectedReturnDate = utstyr_parse_date((string)($data['expectedReturnDate'] ?? ''), 'Forventa innlevering');
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    if ($expectedReturnDate < $today) {
        utstyr_json(['error' => 'Forventa innlevering kan ikkje vere i fortida.'], 400);
    }

    $note = utstyr_clean_text($data['note'] ?? '', 255);

    $pdo = utstyr_pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, name, loan_mode, total_quantity FROM utstyr_items WHERE id = ? AND active = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            $pdo->rollBack();
            utstyr_json(['error' => 'Fann ikkje aktivt utstyr.'], 404);
        }

        $quantity = (string)$item['loan_mode'] === 'unique'
            ? 1
            : utstyr_parse_int($data['quantity'] ?? 1, 'Mengd');

        $activeQuantity = active_quantity_locked($pdo, $itemId);
        $totalQuantity = (string)$item['loan_mode'] === 'unique' ? 1 : max(1, (int)$item['total_quantity']);
        $available = $totalQuantity - $activeQuantity;
        if ($quantity > $available) {
            $pdo->rollBack();
            utstyr_json(['error' => 'Det er ikkje nok ledig utstyr til dette utlånet.'], 409);
        }

        $pdo->prepare(
            'INSERT INTO utstyr_loans
                (item_id, borrower_name, quantity, expected_return_date, note, created_by_role)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$itemId, $borrowerName, $quantity, $expectedReturnDate, $note, (string)$user['role']]);

        $pdo->commit();
        $response = load_dashboard_data($user);
        $response['ok'] = true;
        $response['message'] = 'Utlånet er registrert.';
        $response['item'] = load_item_by_id($itemId);
        $response['itemLoans'] = load_active_loans($itemId);
        return $response;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function return_loan(array $data, array $user): array
{
    $loanId = (int)($data['loanId'] ?? 0);
    if ($loanId <= 0) {
        utstyr_json(['error' => 'Manglar utlån.'], 400);
    }

    $returnNote = utstyr_clean_text($data['returnNote'] ?? '', 255);

    $pdo = utstyr_pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, item_id, borrower_name
               FROM utstyr_loans
              WHERE id = ? AND returned_at IS NULL
              LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();
        if (!$loan) {
            $pdo->rollBack();
            utstyr_json(['error' => 'Fann ikkje eit aktivt utlån.'], 404);
        }

        $returnedByName = utstyr_clean_text($data['returnedByName'] ?? '', 120);
        if ($returnedByName === '') {
            $returnedByName = (string)$loan['borrower_name'];
        }

        $pdo->prepare(
            'UPDATE utstyr_loans
                SET returned_at = CURRENT_TIMESTAMP,
                    returned_by_name = ?,
                    returned_by_role = ?,
                    return_note = ?
              WHERE id = ?'
        )->execute([$returnedByName, (string)$user['role'], $returnNote, $loanId]);

        $itemId = (int)$loan['item_id'];
        $pdo->commit();
        $response = load_dashboard_data($user);
        $response['ok'] = true;
        $response['message'] = 'Innleveringa er registrert.';
        $response['item'] = load_item_by_id($itemId);
        $response['itemLoans'] = load_active_loans($itemId);
        return $response;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function resolve_item_id(array $data): int
{
    $itemId = (int)($data['itemId'] ?? 0);
    if ($itemId > 0) {
        return $itemId;
    }

    $token = trim((string)($data['token'] ?? ''));
    if ($token === '') {
        utstyr_json(['error' => 'Manglar utstyr.'], 400);
    }

    $stmt = utstyr_pdo()->prepare('SELECT id FROM utstyr_items WHERE qr_token = ? AND active = 1 LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        utstyr_json(['error' => 'Fann ikkje utstyret.'], 404);
    }
    return (int)$row['id'];
}

function active_quantity_locked(PDO $pdo, int $itemId): int
{
    $stmt = $pdo->prepare('SELECT quantity FROM utstyr_loans WHERE item_id = ? AND returned_at IS NULL FOR UPDATE');
    $stmt->execute([$itemId]);
    $sum = 0;
    foreach ($stmt->fetchAll() as $row) {
        $sum += (int)$row['quantity'];
    }
    return $sum;
}

function load_admin_report(array $data, array $user): array
{
    $from = utstyr_parse_date((string)($data['from'] ?? ''), 'Frå-dato');
    $to = utstyr_parse_date((string)($data['to'] ?? ''), 'Til-dato');
    if ($from > $to) {
        utstyr_json(['error' => 'Frå-dato kan ikkje vere etter til-dato.'], 400);
    }

    $status = (string)($data['status'] ?? 'all');
    if (!in_array($status, ['all', 'active', 'returned', 'overdue'], true)) {
        utstyr_json(['error' => 'Ukjend rapportfilter.'], 400);
    }

    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $where = 'WHERE lo.borrowed_at BETWEEN ? AND ?';
    if ($status === 'active') {
        $where .= ' AND lo.returned_at IS NULL';
    } elseif ($status === 'returned') {
        $where .= ' AND lo.returned_at IS NOT NULL';
    } elseif ($status === 'overdue') {
        $where .= ' AND lo.returned_at IS NULL AND lo.expected_return_date < CURRENT_DATE';
    }

    $stmt = utstyr_pdo()->prepare(
        'SELECT lo.id, lo.item_id, lo.borrower_name, lo.quantity, lo.expected_return_date,
                lo.borrowed_at, lo.returned_at, lo.returned_by_name, lo.note, lo.return_note,
                lo.created_by_role, lo.returned_by_role,
                i.name AS item_name, i.loan_mode, i.qr_token, c.name AS category_name, l.name AS location_name
           FROM utstyr_loans lo
           JOIN utstyr_items i ON i.id = lo.item_id
           JOIN utstyr_categories c ON c.id = i.category_id
      LEFT JOIN utstyr_locations l ON l.id = i.location_id
          ' . $where . '
          ORDER BY lo.borrowed_at DESC, lo.id DESC'
    );
    $stmt->execute($params);
    $rows = array_map('map_history_loan', $stmt->fetchAll());

    return [
        'ok' => true,
        'from' => $from,
        'to' => $to,
        'status' => $status,
        'rows' => $rows,
        'summary' => [
            'loans' => count($rows),
            'active' => count(array_filter($rows, static fn(array $row): bool => !$row['isReturned'])),
            'overdue' => count(array_filter($rows, static fn(array $row): bool => $row['isOverdue'] && !$row['isReturned'])),
        ],
    ];
}

function create_item(array $data, array $user): array
{
    $payload = item_payload($data, null);
    $token = ensure_unique_token(utstyr_slug((string)($data['qrToken'] ?? $payload['name'])), null);

    utstyr_pdo()->prepare(
        'INSERT INTO utstyr_items
            (category_id, location_id, name, description, loan_mode, total_quantity, default_loan_days, qr_token, active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $payload['categoryId'],
        $payload['locationId'],
        $payload['name'],
        $payload['description'],
        $payload['loanMode'],
        $payload['totalQuantity'],
        $payload['defaultLoanDays'],
        $token,
        $payload['active'] ? 1 : 0,
    ]);

    $response = load_dashboard_data($user);
    $response['ok'] = true;
    $response['message'] = 'Utstyret er oppretta.';
    return $response;
}

function save_item(array $data, array $user): array
{
    $itemId = (int)($data['itemId'] ?? 0);
    if ($itemId <= 0) {
        utstyr_json(['error' => 'Manglar utstyr.'], 400);
    }

    $payload = item_payload($data, $itemId);
    $activeQuantity = active_quantity_for_item($itemId);
    if ($activeQuantity > $payload['totalQuantity']) {
        utstyr_json(['error' => 'Utstyret har fleire aktive utlån enn ny total mengd. Lever inn først.'], 409);
    }

    $token = trim((string)($data['qrToken'] ?? ''));
    $token = $token === ''
        ? ensure_unique_token(utstyr_slug($payload['name']), $itemId)
        : ensure_unique_token(utstyr_slug($token), $itemId);

    utstyr_pdo()->prepare(
        'UPDATE utstyr_items
            SET category_id = ?,
                location_id = ?,
                name = ?,
                description = ?,
                loan_mode = ?,
                total_quantity = ?,
                default_loan_days = ?,
                qr_token = ?,
                active = ?
          WHERE id = ?'
    )->execute([
        $payload['categoryId'],
        $payload['locationId'],
        $payload['name'],
        $payload['description'],
        $payload['loanMode'],
        $payload['totalQuantity'],
        $payload['defaultLoanDays'],
        $token,
        $payload['active'] ? 1 : 0,
        $itemId,
    ]);

    $response = load_dashboard_data($user);
    $response['ok'] = true;
    $response['message'] = 'Utstyret er lagra.';
    return $response;
}

function item_payload(array $data, ?int $currentItemId): array
{
    $categoryId = (int)($data['categoryId'] ?? 0);
    if ($categoryId <= 0 || !category_exists($categoryId)) {
        utstyr_json(['error' => 'Vel kategori.'], 400);
    }

    $locationId = (int)($data['locationId'] ?? 0);
    $locationId = $locationId > 0 ? $locationId : null;
    if ($locationId !== null && !location_exists($locationId)) {
        utstyr_json(['error' => 'Vel gyldig plassering.'], 400);
    }

    $name = utstyr_clean_text($data['name'] ?? '', 160);
    if ($name === '') {
        utstyr_json(['error' => 'Namn på utstyr manglar.'], 400);
    }

    $loanMode = (string)($data['loanMode'] ?? 'unique');
    if (!in_array($loanMode, ['unique', 'quantity'], true)) {
        utstyr_json(['error' => 'Vel utlånstype.'], 400);
    }

    $totalQuantity = $loanMode === 'unique'
        ? 1
        : utstyr_parse_int($data['totalQuantity'] ?? 1, 'Total mengd');

    return [
        'categoryId' => $categoryId,
        'locationId' => $locationId,
        'name' => $name,
        'description' => utstyr_clean_text($data['description'] ?? '', 500),
        'loanMode' => $loanMode,
        'totalQuantity' => $totalQuantity,
        'defaultLoanDays' => utstyr_parse_int($data['defaultLoanDays'] ?? 7, 'Standard lånetid', 1, 365),
        'active' => !empty($data['active']),
    ];
}

function category_exists(int $categoryId): bool
{
    $stmt = utstyr_pdo()->prepare('SELECT id FROM utstyr_categories WHERE id = ? LIMIT 1');
    $stmt->execute([$categoryId]);
    return (bool)$stmt->fetch();
}

function location_exists(int $locationId): bool
{
    $stmt = utstyr_pdo()->prepare('SELECT id FROM utstyr_locations WHERE id = ? LIMIT 1');
    $stmt->execute([$locationId]);
    return (bool)$stmt->fetch();
}

function active_quantity_for_item(int $itemId): int
{
    $stmt = utstyr_pdo()->prepare('SELECT COALESCE(SUM(quantity), 0) AS quantity FROM utstyr_loans WHERE item_id = ? AND returned_at IS NULL');
    $stmt->execute([$itemId]);
    return (int)($stmt->fetch()['quantity'] ?? 0);
}

function save_category(array $data, array $user): array
{
    $name = utstyr_clean_text($data['name'] ?? '', 120);
    if ($name === '') {
        utstyr_json(['error' => 'Kategorinamn manglar.'], 400);
    }

    $categoryId = (int)($data['categoryId'] ?? 0);
    $sortOrder = (int)($data['sortOrder'] ?? 100);
    $active = !empty($data['active']) ? 1 : 0;

    if ($categoryId > 0) {
        utstyr_pdo()->prepare(
            'UPDATE utstyr_categories SET name = ?, sort_order = ?, active = ? WHERE id = ?'
        )->execute([$name, $sortOrder, $active, $categoryId]);
    } else {
        utstyr_pdo()->prepare(
            'INSERT INTO utstyr_categories (name, sort_order, active) VALUES (?, ?, ?)'
        )->execute([$name, $sortOrder, $active]);
    }

    $response = load_dashboard_data($user);
    $response['ok'] = true;
    $response['message'] = 'Kategorien er lagra.';
    return $response;
}

function save_location(array $data, array $user): array
{
    $name = utstyr_clean_text($data['name'] ?? '', 120);
    if ($name === '') {
        utstyr_json(['error' => 'Plassering manglar.'], 400);
    }

    $locationId = (int)($data['locationId'] ?? 0);
    $sortOrder = (int)($data['sortOrder'] ?? 100);
    $active = !empty($data['active']) ? 1 : 0;

    if ($locationId > 0) {
        utstyr_pdo()->prepare(
            'UPDATE utstyr_locations SET name = ?, sort_order = ?, active = ? WHERE id = ?'
        )->execute([$name, $sortOrder, $active, $locationId]);
    } else {
        utstyr_pdo()->prepare(
            'INSERT INTO utstyr_locations (name, sort_order, active) VALUES (?, ?, ?)'
        )->execute([$name, $sortOrder, $active]);
    }

    $response = load_dashboard_data($user);
    $response['ok'] = true;
    $response['message'] = 'Plasseringa er lagra.';
    return $response;
}

function ensure_unique_token(string $baseToken, ?int $currentItemId): string
{
    $baseToken = utstyr_slug($baseToken);
    $candidate = $baseToken;
    $n = 2;
    $stmt = utstyr_pdo()->prepare(
        'SELECT id FROM utstyr_items WHERE qr_token = ? AND (? IS NULL OR id <> ?) LIMIT 1'
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
