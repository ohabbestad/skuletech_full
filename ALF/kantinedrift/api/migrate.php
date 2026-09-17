<?php
declare(strict_types=1);
// CLI-only: no publicly reachable database migration endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/db.php';
require __DIR__ . '/operations.php';
if (!in_array('--backup-confirmed', $argv, true)) {
    fwrite(STDERR, "Ta sikkerheitskopi og set kantina i vedlikehald før køyring. Bruk --backup-confirmed.\n"); exit(1);
}
$pdo = kantine_pdo();
try {
    // MySQL DDL commits implicitly; idempotent DDL precedes the data transaction.
    $sql = file_get_contents(__DIR__ . '/../sql/migrations/002_daily_operations.sql');
    foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
    $pdo->exec('INSERT IGNORE INTO kantine_revision (id,revision) VALUES (1,0)');
    $pdo->beginTransaction();
    $pdo->query('SELECT revision FROM kantine_revision WHERE id=1 FOR UPDATE')->fetchColumn();
    if ($pdo->query('SELECT version FROM kantine_schema_versions WHERE version=2')->fetchColumn()) {
        $pdo->commit(); echo "Versjon 2 er allereie installert. Ingen data endra.\n"; exit;
    }
    $templates = []; $legacyKeys = []; $index = ['early' => 0, 'late' => 0];
    foreach ($pdo->query('SELECT id,shift_name,label FROM kantine_task_list ORDER BY shift_name,sort_order,id') as $row) {
        $id = 'legacy_' . $row['id'];
        $templates[] = ['id' => $id, 'label' => $row['label'], 'shift' => $row['shift_name'], 'weekday' => 0];
        $legacyKeys[$row['shift_name'] . '_' . $index[$row['shift_name']]++] = $id;
    }
    $pdo->prepare('INSERT INTO kantine_task_templates (effective_date,tasks_json) VALUES (?,?)')->execute(['1000-01-01', kv_json_encode($templates)]);
    $staff = load_bemanning(); $checks = load_values('tasks', true); $calendar = load_calendar();
    // Also preserve task dates belonging to previously removed weeks.
    $dates = array_unique(array_merge(array_column($calendar, 'id'), array_keys($checks))); $byDate = array_column($calendar, null, 'id');
    foreach ($dates as $date) {
        $day = $byDate[$date] ?? null; $override = null;
        if ($day && ($day['tidlegOverstyre'] !== '' || $day['sentOverstyre'] !== '')) {
            $weekday = kv_weekday($date); $base = $staff['turnus'][$day['turnusType']][$weekday] ?? [];
            $override = kv_json_encode(['leader' => $staff['driftsleiarar'][$weekday] ?? '',
                'early' => kv_names(explode(',', $day['tidlegOverstyre'] !== '' ? $day['tidlegOverstyre'] : ($base['tidleg'] ?? ''))),
                'late' => kv_names(explode(',', $day['sentOverstyre'] !== '' ? $day['sentOverstyre'] : ($base['seint'] ?? '')))]);
        }
        $pdo->prepare('INSERT INTO kantine_day_state (date_id,staffing_json,tasks_json) VALUES (?,?,?)')->execute([$date, $override, kv_json_encode(kv_tasks_for_date($templates, $date))]);
        foreach ($checks[$date] ?? [] as $key => $done) if (isset($legacyKeys[$key])) kv_value('tasks', $date, $legacyKeys[$key], $done);
    }
    $pdo->exec('INSERT INTO kantine_schema_versions (version) VALUES (2)'); $pdo->exec('UPDATE kantine_revision SET revision=revision+1 WHERE id=1'); $pdo->commit();
    echo 'Versjon 2 installert. Dagslister: ' . count($dates) . ". Gamle verdiar er bevarte.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Migrering avbroten: ' . $e->getMessage() . "\n"); exit(1);
}
