<?php
declare(strict_types=1);

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

    session_regenerate_id(true);
    $now = time();
    $_SESSION['kantine_user'] = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
    ];
    $_SESSION['kantine_login_at'] = $now;
    $_SESSION['kantine_last_seen_at'] = $now;
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

function load_menus(): array
{
    $menus = load_values('menus', false);
    foreach ($menus as $dateId => $items) {
        $alternativeFree = (($items['alternativ_allergenfri'] ?? '') === '1');
        $menus[$dateId]['allergens'] = normalize_allergens_from_storage($items['allergens'] ?? '[]');
        $menus[$dateId]['alternativ'] = (string)($items['alternativ'] ?? '');
        $menus[$dateId]['alternativ_allergens'] = $alternativeFree
            ? []
            : normalize_allergens_from_storage($items['alternativ_allergens'] ?? '[]');
        $menus[$dateId]['alternativ_allergenfri'] = $alternativeFree;
    }
    return $menus;
}

function allowed_allergen_ids(): array
{
    return [
        'gluten',
        'skalldyr',
        'egg',
        'fisk',
        'peanotter',
        'soya',
        'mjolk',
        'laktose',
        'notter',
        'selleri',
        'sennep',
        'sesam',
        'sulfitt',
        'lupin',
        'blautdyr',
    ];
}

function normalize_allergens_from_storage($raw): array
{
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    return normalize_allergens(is_array($decoded) ? $decoded : []);
}

function normalize_allergens(array $raw): array
{
    $allowed = array_flip(allowed_allergen_ids());
    $out = [];
    foreach ($raw as $id) {
        $id = (string)$id;
        if (isset($allowed[$id]) && !in_array($id, $out, true)) {
            $out[] = $id;
        }
    }
    return $out;
}
