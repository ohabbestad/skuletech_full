<?php
declare(strict_types=1);
require_once __DIR__ . '/domain.php';
require_once __DIR__ . '/read-model.php';

function kv_template(string $date): array
{
    $stmt = kantine_pdo()->prepare('SELECT tasks_json FROM kantine_task_templates WHERE effective_date <= ? ORDER BY effective_date DESC LIMIT 1');
    $stmt->execute([$date]);
    $json = $stmt->fetchColumn();
    if ($json === false) throw new RuntimeException('Oppgåvemal manglar.');
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

function kv_assert_migrated(): void
{
    try { $version = kantine_pdo()->query('SELECT MAX(version) FROM kantine_schema_versions')->fetchColumn(); }
    catch (PDOException $e) { kantine_json(['error' => 'Oppgraderinga av databasen må fullførast før innlogging.'], 503); }
    if ((int)$version < 2) kantine_json(['error' => 'Oppgraderinga av databasen må fullførast før innlogging.'], 503);
}

function kv_public(): array
{
    $calendar = array_map(static function (array $day): array {
        $public = array_intersect_key($day, array_flip(['id', 'veke', 'turnusType', 'status']));
        // Existing TV clients display the reason for a closed day.
        $public['merknad'] = $day['status'] === 'closed' ? $day['merknad'] : '';
        return $public;
    }, load_calendar());
    return ['kalender' => $calendar, 'menus' => load_menus()];
}

function kv_load(): array
{
    $pdo = kantine_pdo();
    $pdo->beginTransaction();
    try {
        // Pairs data and revision even on READ COMMITTED servers.
        $revision = (int)$pdo->query('SELECT revision FROM kantine_revision WHERE id = 1 LOCK IN SHARE MODE')->fetchColumn();
        $calendar = load_calendar();
        $staff = load_bemanning();
        $states = [];
        $checks = load_values('tasks', true);
        $substitutes = load_vikarer();
        $now = kv_now();
        foreach ($pdo->query('SELECT * FROM kantine_day_state') as $row) $states[$row['date_id']] = $row;
        foreach ($calendar as &$day) {
            $date = $day['id'];
            $day['weekStart'] = kv_monday($date);
            $weekday = kv_weekday($date);
            $tpl = $staff['turnus'][$day['turnusType']][$weekday] ?? [];
            $base = ['leader' => $staff['driftsleiarar'][$weekday] ?? '', 'early' => kv_names(explode(',', $tpl['tidleg'] ?? '')), 'late' => kv_names(explode(',', $tpl['seint'] ?? ''))];
            $state = $states[$date] ?? null;
            if (!$state) throw new RuntimeException('Dagslista manglar. Køyr migreringa.');
            $override = $state['staffing_json'] !== null ? json_decode($state['staffing_json'], true, 512, JSON_THROW_ON_ERROR) : null;
            // SQL imports preserve the original comma-separated strings in arrays.
            // Use the same trimming/deduplication rules as normal staffing writes.
            if ($override !== null) {
                $override['early'] = kv_names($override['early']);
                $override['late'] = kv_names($override['late']);
            }
            $day['baseStaffing'] = $base;
            $day['staffing'] = $override ?? $base;
            $day['staffingCustom'] = $override !== null;
            $day['tasksCustom'] = (bool)$state['tasks_custom'];
            $day['taskRows'] = json_decode($state['tasks_json'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($day['taskRows'] as &$task) $task['done'] = $checks[$date][$task['id']] ?? false;
            unset($task);
            $day['canCheck'] = kv_can_check($date, $day['status'], $now);
            $day['substitutes'] = $substitutes[$date] ?? ['tidleg' => [], 'seint' => []];
        }
        unset($day);
        $result = ['revision' => $revision, 'today' => $now->format('Y-m-d'), 'serverTime' => $now->format(DATE_ATOM),
            'kalender' => $calendar, 'bemanning' => $staff, 'menus' => load_menus(), 'attendance' => load_values('attendance', true),
            'templateRows' => kv_template($now->modify('+1 day')->format('Y-m-d')), 'templateEffective' => $now->modify('+1 day')->format('Y-m-d')];
        $pdo->commit();
        return $result;
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

function kv_day(string $date): array
{
    $stmt = kantine_pdo()->prepare('SELECT c.*, s.staffing_json, s.tasks_json, s.tasks_custom FROM kantine_calendar_days c JOIN kantine_day_state s ON s.date_id = c.date_id WHERE c.date_id = ?');
    $stmt->execute([$date]);
    $row = $stmt->fetch();
    if (!$row) throw new InvalidArgumentException('Dagen finst ikkje i planen.');
    return $row;
}

function kv_value(string $kind, string $date, string $key, $value): void
{
    $boolean = is_bool($value);
    kantine_pdo()->prepare('INSERT INTO kantine_values (value_kind,date_id,item_key,value_text,value_bool) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),value_bool=VALUES(value_bool)')
        ->execute([$kind, $date, $key, $boolean ? null : $value, $boolean ? (int)$value : null]);
}

function kv_save(array $data, array $user): void
{
    $type = $data['type'] ?? '';
    $rules = ['tilsett' => ['task_check'], 'driftsleiar' => ['attendance', 'menu_day', 'vikar_add', 'vikar_remove'],
        'laerar' => ['task_check', 'attendance', 'menu_day', 'vikar_add', 'vikar_remove', 'day_staffing', 'day_tasks', 'task_templates', 'calendar_day', 'create_week', 'delete_week', 'staff_templates']];
    if (!is_string($type) || !in_array($type, $rules[$user['role']] ?? [], true)) kantine_json(['error' => 'Rolla di kan ikkje utføre denne handlinga.'], 403);
    if (!isset($data['revision']) || !is_int($data['revision'])) kantine_json(['error' => 'Last sida på nytt før lagring.'], 409);
    $pdo = kantine_pdo();
    $pdo->beginTransaction();
    try {
        // Every writer locks before reading; conflict checks and writes are atomic.
        $revision = (int)$pdo->query('SELECT revision FROM kantine_revision WHERE id = 1 FOR UPDATE')->fetchColumn();
        if ($revision !== $data['revision']) {
            $pdo->rollBack();
            kantine_json(['error' => 'Nokon har endra planen sidan du opna han. Utkastet ditt er bevart. Hent siste versjon og samanlikn før du lagrar.', 'conflict' => true], 409);
        }
        $date = null; $day = null;
        if (in_array($type, ['task_check', 'attendance', 'menu_day', 'vikar_add', 'vikar_remove', 'day_staffing', 'day_tasks', 'calendar_day'], true)) {
            $date = kv_date($data['dateId'] ?? null); $day = kv_day($date);
        }
        switch ($type) {
            case 'task_check':
                if ($user['role'] === 'tilsett' && !kv_can_check($date, $day['status'], kv_now())) {
                    $pdo->rollBack(); kantine_json(['error' => 'Du kan krysse av på ein open arbeidsdag mellom klokka 11.00 og 12.00.'], 403);
                }
                $ids = array_column(json_decode($day['tasks_json'], true), 'id');
                if (!in_array($data['key'] ?? null, $ids, true) || !is_bool($data['value'] ?? null)) throw new InvalidArgumentException('Ugyldig oppgåve eller avkryssing.');
                kv_value('tasks', $date, $data['key'], $data['value']); break;
            case 'attendance':
                $name = kv_text($data['name'] ?? '', 160);
                if ($name === '' || !is_bool($data['value'] ?? null)) throw new InvalidArgumentException('Ugyldig fråvær.');
                kv_value('attendance', $date, $name, $data['value']); break;
            case 'menu_day':
                $alternative = kv_text($data['alternative'] ?? '', 2000);
                $free = $alternative !== '' && ($data['alternativeFree'] ?? false) === true;
                foreach (['allergens', 'alternativeAllergens'] as $field) {
                    if (!is_array($data[$field] ?? null) || array_diff($data[$field], allowed_allergen_ids())) throw new InvalidArgumentException('Ugyldig allergenval.');
                }
                $values = ['dagens' => kv_text($data['value'] ?? '', 2000), 'allergens' => kv_json_encode(normalize_allergens($data['allergens'])),
                    'alternativ' => $alternative, 'alternativ_allergens' => kv_json_encode($free ? [] : normalize_allergens($data['alternativeAllergens'])), 'alternativ_allergenfri' => $free ? '1' : '0'];
                foreach ($values as $key => $value) kv_value('menus', $date, $key, $value); break;
            case 'vikar_add':
            case 'vikar_remove':
                $name = kv_text($data['name'] ?? '', 160); $shift = $data['shift'] ?? '';
                if ($name === '' || !in_array($shift, ['tidleg', 'seint'], true)) throw new InvalidArgumentException('Vel namn og vakt.');
                $pdo->prepare('DELETE FROM kantine_substitutes WHERE date_id=? AND shift_name=? AND student_name=?')->execute([$date, $shift, $name]);
                if ($type === 'vikar_add') $pdo->prepare('INSERT INTO kantine_substitutes (date_id,shift_name,student_name) VALUES (?,?,?)')->execute([$date, $shift, $name]);
                break;
            case 'day_staffing':
                $staff = null;
                if (($data['reset'] ?? false) !== true) $staff = kv_json_encode(['leader' => kv_text($data['leader'] ?? '', 160), 'early' => kv_names($data['early'] ?? null), 'late' => kv_names($data['late'] ?? null)]);
                $pdo->prepare('UPDATE kantine_day_state SET staffing_json=? WHERE date_id=?')->execute([$staff, $date]); break;
            case 'day_tasks':
                $reset = ($data['reset'] ?? false) === true;
                $tasks = $reset ? kv_tasks_for_date(kv_template($date), $date) : kv_task_rows($data['rows'] ?? null);
                $pdo->prepare('UPDATE kantine_day_state SET tasks_json=?,tasks_custom=? WHERE date_id=?')->execute([kv_json_encode($tasks), $reset ? 0 : 1, $date]);
                // Check values for removed IDs remain, so restoring a task restores its history.
                break;
            case 'task_templates':
                $rows = kv_task_rows($data['rows'] ?? null, true); $tomorrow = kv_now()->modify('+1 day')->format('Y-m-d');
                $pdo->prepare('INSERT INTO kantine_task_templates (effective_date,tasks_json) VALUES (?,?) ON DUPLICATE KEY UPDATE tasks_json=VALUES(tasks_json)')->execute([$tomorrow, kv_json_encode($rows)]);
                $stmt = $pdo->prepare('SELECT date_id FROM kantine_day_state WHERE date_id>=? AND tasks_custom=0'); $stmt->execute([$tomorrow]);
                $update = $pdo->prepare('UPDATE kantine_day_state SET tasks_json=? WHERE date_id=?');
                foreach ($stmt->fetchAll() as $row) $update->execute([kv_json_encode(kv_tasks_for_date($rows, $row['date_id'])), $row['date_id']]); break;
            case 'calendar_day':
                if (!in_array($data['status'] ?? '', ['open', 'closed'], true)) throw new InvalidArgumentException('Ugyldig status.');
                $pdo->prepare('UPDATE kantine_calendar_days SET status=?,merknad=? WHERE date_id=?')->execute([$data['status'], kv_text($data['note'] ?? ''), $date]); break;
            case 'create_week':
                $monday = kv_date($data['monday'] ?? null);
                if (kv_monday($monday) !== $monday || !in_array($data['turnus'] ?? '', ['A', 'B', 'C', 'Ferie'], true)) throw new InvalidArgumentException('Vel ein måndag og gyldig turnus.');
                $start = new DateTimeImmutable($monday);
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM kantine_calendar_days WHERE date_id BETWEEN ? AND ?');
                $stmt->execute([$monday, $start->modify('+4 days')->format('Y-m-d')]);
                if ((int)$stmt->fetchColumn() > 0) throw new InvalidArgumentException('Veka finst allereie. Opne dagane for å endre dei.');
                for ($i = 0; $i < 5; $i++) {
                    $dayDate = $start->modify("+$i days"); $id = $dayDate->format('Y-m-d');
                    $pdo->prepare('INSERT INTO kantine_calendar_days (date_id,week_no,turnus_type,status) VALUES (?,?,?,?)')->execute([$id, (int)$dayDate->format('W'), $data['turnus'], $data['turnus'] === 'Ferie' ? 'closed' : 'open']);
                    $pdo->prepare('INSERT IGNORE INTO kantine_day_state (date_id,tasks_json) VALUES (?,?)')->execute([$id, kv_json_encode(kv_tasks_for_date(kv_template($id), $id))]);
                } break;
            case 'delete_week':
                $monday = kv_date($data['monday'] ?? null);
                if (kv_monday($monday) !== $monday) throw new InvalidArgumentException('Ugyldig vekestart.');
                $pdo->prepare('DELETE FROM kantine_calendar_days WHERE date_id BETWEEN ? AND ?')->execute([$monday, (new DateTimeImmutable($monday))->modify('+4 days')->format('Y-m-d')]); break;
            case 'staff_templates':
                $items = $data['items'] ?? null;
                if (!is_array($items) || count($items) !== 35) throw new InvalidArgumentException('Bemanningsoppsettet er ufullstendig.');
                $validated = [];
                foreach ($items as $item) {
                    $key = $item['key'] ?? '';
                    if (!is_string($key) || !preg_match('/^(driftsleiar_(Måndag|Tysdag|Onsdag|Torsdag|Fredag)|turnus_[ABC]_(Måndag|Tysdag|Onsdag|Torsdag|Fredag)_(tidleg|seint))$/uD', $key) || isset($validated[$key])) throw new InvalidArgumentException('Ugyldig bemanningsfelt.');
                    $value = kv_text($item['value'] ?? '', 4000);
                    $validated[$key] = strpos($key, 'driftsleiar_') === 0 ? kv_text($value, 160) : implode(', ', kv_names(explode(',', $value)));
                }
                // Freeze current/historic staffing before changing the recurring source.
                $oldStaff = load_bemanning();
                $stmt = $pdo->prepare('SELECT c.* FROM kantine_calendar_days c JOIN kantine_day_state s ON c.date_id=s.date_id WHERE c.date_id<=? AND s.staffing_json IS NULL'); $stmt->execute([kv_now()->format('Y-m-d')]);
                foreach ($stmt->fetchAll() as $oldDay) {
                    $weekday = kv_weekday($oldDay['date_id']); $base = $oldStaff['turnus'][$oldDay['turnus_type']][$weekday] ?? [];
                    $snapshot = ['leader' => $oldStaff['driftsleiarar'][$weekday] ?? '', 'early' => kv_names(explode(',', $base['tidleg'] ?? '')), 'late' => kv_names(explode(',', $base['seint'] ?? ''))];
                    $pdo->prepare('UPDATE kantine_day_state SET staffing_json=? WHERE date_id=?')->execute([kv_json_encode($snapshot), $oldDay['date_id']]);
                }
                $stmt = $pdo->prepare('INSERT INTO kantine_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
                foreach ($validated as $key => $value) $stmt->execute([$key, $value]); break;
        }
        $pdo->exec('UPDATE kantine_revision SET revision=revision+1 WHERE id=1'); $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
