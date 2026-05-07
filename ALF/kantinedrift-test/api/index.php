<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

kantine_start_session();

try {
    $data = kantine_request();
    $action = (string)($data['action'] ?? 'load');
    $role = (string)($data['role'] ?? '');

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        kantine_json(['ok' => true]);
    }

    if (!empty($data['password'])) {
        login_with_password($role, (string)$data['password']);
    }

    if ($action === 'load') {
        if ($role !== 'infoskjerm') {
            $user = kantine_require_role(['tilsett', 'driftsleiar', 'laerar']);
            if (($user['role'] ?? '') !== $role && ($user['role'] ?? '') !== 'laerar') {
                kantine_json(['error' => 'Du må logge inn med riktig rolle.'], 403);
            }
        }
        kantine_json(load_all());
    }

    if ($action === 'save') {
        $user = kantine_require_role(['tilsett', 'driftsleiar', 'laerar']);
        save_payload($data, $user);
        kantine_json(load_all());
    }

    kantine_json(['error' => 'Ukjend handling.'], 400);
} catch (Throwable $e) {
    kantine_json(['error' => 'Serverfeil: ' . $e->getMessage()], 500);
}

function login_with_password(string $role, string $password): void
{
    if (!in_array($role, ['tilsett', 'driftsleiar', 'laerar'], true)) {
        kantine_json(['error' => 'Ukjend rolle.'], 400);
    }

    $stmt = kantine_pdo()->prepare(
        'SELECT id, username, role, password_hash FROM kantine_users WHERE role = ? AND active = 1 LIMIT 1'
    );
    $stmt->execute([$role]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        kantine_json(['error' => 'Feil passord.'], 401);
    }

    $_SESSION['kantine_user'] = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
    ];
}

function load_all(): array
{
    return [
        'kalender' => load_calendar(),
        'bemanning' => load_bemanning(),
        'tasksList' => load_task_list(),
        'attendance' => load_values('attendance', true),
        'tasks' => load_values('tasks', true),
        'menus' => load_values('menus', false),
        'vikarer' => load_vikarer(),
    ];
}

function load_calendar(): array
{
    $rows = kantine_pdo()
        ->query('SELECT * FROM kantine_calendar_days ORDER BY date_id')
        ->fetchAll();

    return array_map(static fn(array $row): array => [
        'id' => $row['date_id'],
        'veke' => (int)$row['week_no'],
        'turnusType' => $row['turnus_type'],
        'status' => $row['status'],
        'merknad' => $row['merknad'] ?? '',
        'tidlegOverstyre' => $row['tidleg_overstyre'] ?? '',
        'sentOverstyre' => $row['sent_overstyre'] ?? '',
    ], $rows);
}

function load_bemanning(): array
{
    $stmt = kantine_pdo()->query('SELECT setting_key, setting_value FROM kantine_settings');
    $bemanning = ['driftsleiarar' => [], 'turnus' => ['A' => [], 'B' => [], 'C' => []]];

    foreach ($stmt as $row) {
        $key = $row['setting_key'];
        $value = $row['setting_value'] ?? '';
        if (substr($key, 0, strlen('driftsleiar_')) === 'driftsleiar_') {
            $dag = substr($key, strlen('driftsleiar_'));
            $bemanning['driftsleiarar'][$dag] = $value;
            continue;
        }

        if (preg_match('/^turnus_([ABC])_(.+)_(tidleg|seint)$/u', $key, $m)) {
            [, $turnus, $dag, $shift] = $m;
            if (!isset($bemanning['turnus'][$turnus][$dag])) {
                $bemanning['turnus'][$turnus][$dag] = ['tidleg' => '', 'seint' => ''];
            }
            $bemanning['turnus'][$turnus][$dag][$shift] = $value;
        }
    }

    return $bemanning;
}

function load_task_list(): array
{
    $stmt = kantine_pdo()->query('SELECT shift_name, label FROM kantine_task_list ORDER BY shift_name, sort_order');
    $tasks = ['early' => [], 'late' => []];
    foreach ($stmt as $row) {
        $tasks[$row['shift_name']][] = $row['label'];
    }
    return $tasks;
}

function load_values(string $kind, bool $boolValues): array
{
    $stmt = kantine_pdo()->prepare(
        'SELECT date_id, item_key, value_text, value_bool FROM kantine_values WHERE value_kind = ? ORDER BY date_id, item_key'
    );
    $stmt->execute([$kind]);
    $out = [];
    foreach ($stmt as $row) {
        $out[$row['date_id']][$row['item_key']] = $boolValues
            ? (bool)$row['value_bool']
            : (string)($row['value_text'] ?? '');
    }
    return $out;
}

function load_vikarer(): array
{
    $stmt = kantine_pdo()->query(
        'SELECT date_id, shift_name, student_name FROM kantine_substitutes ORDER BY id'
    );
    $out = [];
    foreach ($stmt as $row) {
        $dateId = $row['date_id'];
        $shift = $row['shift_name'];
        if (!isset($out[$dateId])) {
            $out[$dateId] = ['tidleg' => [], 'seint' => []];
        }
        $out[$dateId][$shift][] = $row['student_name'];
    }
    return $out;
}

function save_payload(array $data, array $user): void
{
    $type = (string)($data['type'] ?? '');
    $role = (string)($user['role'] ?? '');

    $writeRules = [
        'tilsett' => ['tasks'],
        'driftsleiar' => ['attendance', 'menus', 'vikar_add', 'vikar_remove'],
        'laerar' => [
            'attendance', 'tasks', 'menus', 'vikar_add', 'vikar_remove',
            'kalender_veke', 'kalender_dag', 'delete_week', 'bemanning_batch',
        ],
    ];

    if (!in_array($type, $writeRules[$role] ?? [], true)) {
        kantine_json(['error' => 'Rolla di kan ikkje lagre denne typen data.'], 403);
    }

    switch ($type) {
        case 'attendance':
            save_value_bool('attendance', $data);
            break;
        case 'tasks':
            save_value_bool('tasks', $data);
            break;
        case 'menus':
            save_value_text('menus', $data);
            break;
        case 'vikar_add':
            add_vikar($data);
            break;
        case 'vikar_remove':
            remove_vikar($data);
            break;
        case 'kalender_veke':
            create_week($data);
            break;
        case 'kalender_dag':
            update_calendar_day($data);
            break;
        case 'delete_week':
            delete_week($data);
            break;
        case 'bemanning_batch':
            save_bemanning_batch($data);
            break;
        default:
            kantine_json(['error' => 'Ukjend lagringstype.'], 400);
    }
}

function save_value_bool(string $kind, array $data): void
{
    $sql = 'INSERT INTO kantine_values (value_kind, date_id, item_key, value_bool)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE value_bool = VALUES(value_bool), value_text = NULL';
    kantine_pdo()->prepare($sql)->execute([
        $kind,
        (string)$data['dateId'],
        (string)$data['key'],
        !empty($data['value']) ? 1 : 0,
    ]);
}

function save_value_text(string $kind, array $data): void
{
    $sql = 'INSERT INTO kantine_values (value_kind, date_id, item_key, value_text)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), value_bool = NULL';
    kantine_pdo()->prepare($sql)->execute([
        $kind,
        (string)$data['dateId'],
        (string)$data['key'],
        (string)($data['value'] ?? ''),
    ]);
}

function add_vikar(array $data): void
{
    kantine_pdo()->prepare(
        'INSERT INTO kantine_substitutes (date_id, shift_name, student_name) VALUES (?, ?, ?)'
    )->execute([(string)$data['dateId'], (string)$data['shift'], (string)$data['namn']]);
}

function remove_vikar(array $data): void
{
    kantine_pdo()->prepare(
        'DELETE FROM kantine_substitutes WHERE date_id = ? AND shift_name = ? AND student_name = ? LIMIT 1'
    )->execute([(string)$data['dateId'], (string)$data['shift'], (string)$data['namn']]);
}

function create_week(array $data): void
{
    $pdo = kantine_pdo();
    $pdo->beginTransaction();
    try {
        $monday = new DateTimeImmutable((string)$data['mandagDato']);
        $stmt = $pdo->prepare(
            'INSERT INTO kantine_calendar_days
                (date_id, week_no, turnus_type, status, merknad, tidleg_overstyre, sent_overstyre)
             VALUES (?, ?, ?, ?, "", "", "")
             ON DUPLICATE KEY UPDATE week_no = VALUES(week_no), turnus_type = VALUES(turnus_type)'
        );

        for ($i = 0; $i < 5; $i++) {
            $date = $monday->modify("+$i day")->format('Y-m-d');
            $status = ((string)$data['turnusType'] === 'Ferie') ? 'closed' : 'open';
            $stmt->execute([$date, (int)$data['veke'], (string)$data['turnusType'], $status]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function update_calendar_day(array $data): void
{
    $fieldMap = [
        'Status' => 'status',
        'Merknad' => 'merknad',
        'TidlegOverstyre' => 'tidleg_overstyre',
        'SentOverstyre' => 'sent_overstyre',
    ];
    $field = (string)$data['field'];
    if (!isset($fieldMap[$field])) {
        kantine_json(['error' => 'Ukjend kalenderfelt.'], 400);
    }

    $value = (string)($data['value'] ?? '');
    if ($field === 'Status') {
        $value = strtolower($value) === 'stengt' ? 'closed' : 'open';
    }

    $sql = 'UPDATE kantine_calendar_days SET ' . $fieldMap[$field] . ' = ? WHERE date_id = ?';
    kantine_pdo()->prepare($sql)->execute([$value, (string)$data['dateId']]);
}

function delete_week(array $data): void
{
    kantine_pdo()->prepare('DELETE FROM kantine_calendar_days WHERE week_no = ?')->execute([(int)$data['veke']]);
}

function save_bemanning_batch(array $data): void
{
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $stmt = kantine_pdo()->prepare(
        'INSERT INTO kantine_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($items as $item) {
        $stmt->execute([(string)$item['nokkel'], (string)($item['verdi'] ?? '')]);
    }
}
