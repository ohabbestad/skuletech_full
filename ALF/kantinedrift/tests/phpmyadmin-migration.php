<?php
declare(strict_types=1);
// Creates only new, randomly named databases on the isolated loopback fixture.
// Leaves those databases in place for inspection. Never reads production config.
if (PHP_SAPI !== 'cli' || getenv('KANTINE_TEST_CONFIRM') !== 'isolated') exit("Set KANTINE_TEST_CONFIRM=isolated.\n");
require __DIR__ . '/../api/domain.php';
$port = (int)(getenv('KANTINE_TEST_PORT') ?: 33079);
$user = getenv('KANTINE_TEST_USER') ?: 'root';
$pass = getenv('KANTINE_TEST_PASSWORD') ?: '';
$admin = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$prefix = 'kantine_sqltest_' . bin2hex(random_bytes(4));
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function executeSql(PDO $pdo, string $file, bool $continueOnError = false): array {
    $sql = preg_replace('/^--.*$/m', '', file_get_contents($file));
    $messages = [];
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) === '') continue;
        try {
            $query = $pdo->query($statement);
            if ($query->columnCount()) foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) if (isset($row['resultat'])) $messages[] = $row['resultat'];
            $query->closeCursor();
        } catch (PDOException $e) {
            if (!$continueOnError) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            $messages[] = 'SQL_ERROR';
        }
    }
    return $messages;
}
function fixture(string $suffix, bool $empty = false): PDO {
    global $admin, $prefix, $port, $user, $pass;
    $name = $prefix . '_' . $suffix;
    $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    executeSql($pdo, __DIR__ . '/../sql/schema.sql');
    $pdo->exec('DELETE FROM kantine_task_list');
    if (!$empty) {
        $tasks = [[31,'late',20,'Vask «benk»'], [5,'early',30,'Server suppe'], [42,'early',10,"Finn \"utstyr\" / \\ og æøå\nNy linje"], [100,'late',10,'Rydd først']];
        for ($i = 0; $i < 30; $i++) $tasks[] = [200 + $i, 'late', 40 + $i, str_repeat('Lang oppgåve ø ', 12) . $i];
        foreach ($tasks as $task) $pdo->prepare('INSERT INTO kantine_task_list(id,shift_name,sort_order,label) VALUES (?,?,?,?)')->execute($task);
    }
    foreach ([['2026-09-14', 'A', " Elev Å , ,Elev Å, elev å ,\tElev Z\r\n", ''], ['2026-09-15','B','',' Elev C, «Ø» ,Elev C '], ['2026-09-16','C','', ''], ['2026-09-17','Ferie',' ', '']] as $day) {
        $pdo->prepare('INSERT INTO kantine_calendar_days(date_id,week_no,turnus_type,tidleg_overstyre,sent_overstyre) VALUES (?,38,?,?,?)')->execute($day);
    }
    foreach (['Måndag', 'Tysdag', 'Onsdag', 'Torsdag', 'Fredag'] as $day) {
        $items = ['driftsleiar_' . $day => 'Leiar Æ'];
        foreach (['A','B','C'] as $turnus) foreach (['tidleg','seint'] as $shift) $items["turnus_{$turnus}_{$day}_{$shift}"] = " Elev A,Elev B,,Elev A, elev a, \"Ø\" ";
        foreach ($items as $key => $value) $pdo->prepare('INSERT INTO kantine_settings(setting_key,setting_value) VALUES (?,?)')->execute([$key,$value]);
    }
    foreach ([['2026-09-14','early_0',1],['2026-09-14','early_1',0],['2026-09-14','late_0',1],['2031-01-01','late_1',1],['2026-09-14','early_999',1],['2026-09-15','late_1',null]] as $value) {
        $pdo->prepare("INSERT INTO kantine_values(value_kind,date_id,item_key,value_bool) VALUES ('tasks',?,?,?)")->execute($value);
    }
    $pdo->exec("INSERT INTO kantine_values(value_kind,date_id,item_key,value_text) VALUES ('menus','2026-09-14','dagens','Suppe med brød')");
    $pdo->exec("INSERT INTO kantine_values(value_kind,date_id,item_key,value_bool) VALUES ('attendance','2026-09-14','Elev A',0)");
    $pdo->exec("INSERT INTO kantine_substitutes(date_id,shift_name,student_name) VALUES ('2026-09-14','tidleg','Vikar A')");
    return $pdo;
}
function snapshot(PDO $pdo): array {
    $out = [];
    foreach (['kantine_calendar_days','kantine_settings','kantine_task_list','kantine_users','kantine_substitutes','kantine_values','kantine_revision','kantine_schema_versions','kantine_task_templates','kantine_day_state'] as $table) {
        $rows = $pdo->query("SELECT * FROM $table ORDER BY 1,2")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            unset($row['updated_at'], $row['created_at'], $row['applied_at']);
            if (isset($row['tasks_json'])) $row['tasks_json'] = json_decode($row['tasks_json'], true, 512, JSON_THROW_ON_ERROR);
            if (isset($row['staffing_json'])) {
                $row['staffing_json'] = json_decode($row['staffing_json'], true, 512, JSON_THROW_ON_ERROR);
                foreach (['early','late'] as $shift) $row['staffing_json'][$shift] = kv_names($row['staffing_json'][$shift]);
            }
        }
        unset($row);
        $out[$table] = $rows;
    }
    return $out;
}
$sqlFile = __DIR__ . '/../sql/migrations/002_phpmyadmin.sql';
$sqlDb = fixture('sql');
$phpDb = fixture('php');
check(executeSql($sqlDb, $sqlFile) === ['OPPGRADERING FULLFORT'], 'SQL import completed');

// Run the actual CLI migrator in a disposable copy configured only for this test DB.
$scratch = realpath(__DIR__ . '/../../../codex-temp-kantine');
if (!$scratch) throw new RuntimeException('Create ignored codex-temp-kantine folder first.');
$cliFolder = $scratch . '/sql-migration-' . $prefix;
mkdir($cliFolder . '/api', 0777, true); mkdir($cliFolder . '/sql/migrations', 0777, true);
foreach (['migrate.php','db.php','operations.php','domain.php','read-model.php','config.php'] as $file) copy(__DIR__ . '/../api/' . $file, $cliFolder . '/api/' . $file);
copy(__DIR__ . '/../sql/migrations/002_daily_operations.sql', $cliFolder . '/sql/migrations/002_daily_operations.sql');
file_put_contents($cliFolder . '/api/config.local.php', '<?php return ' . var_export(['db_host'=>'127.0.0.1','db_port'=>$port,'db_name'=>$prefix.'_php','db_user'=>$user,'db_pass'=>$pass],true) . ';');
$pipes = [];
$relativeCli = '../sql-migration-' . $prefix . '/api/migrate.php';
$process = proc_open([PHP_BINARY, '-d', 'extension_dir=ext', $relativeCli, '--backup-confirmed'], [1=>['pipe','w'],2=>['pipe','w']], $pipes, $scratch . '/php');
$output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
check(proc_close($process) === 0, 'CLI migration: ' . $error);
check(snapshot($sqlDb) === snapshot($phpDb), 'SQL migration matches PHP migration, including names, JSON, ordering, false checks and orphan dates');
$before = snapshot($sqlDb);
check(executeSql($sqlDb, $sqlFile) === ['ALLEREIE OPPGRADERT - ingen data endra'], 'Second SQL import is a no-op');
check(snapshot($sqlDb) === $before, 'Repeated import preserves every row');
$sqlDb->exec("UPDATE kantine_day_state SET tasks_custom=1,tasks_json='[]' WHERE date_id='2026-09-14'");
$sqlDb->exec('UPDATE kantine_revision SET revision=9 WHERE id=1');
$before = snapshot($sqlDb);
executeSql($sqlDb, $sqlFile);
check(snapshot($sqlDb) === $before, 'Re-import after real use preserves custom lists and revision');
$empty = fixture('empty', true);
check(executeSql($empty, $sqlFile) === ['OPPGRADERING FULLFORT'], 'Empty task template migrates');
check($empty->query("SELECT tasks_json FROM kantine_task_templates")->fetchColumn() === '[]', 'Empty list JSON valid');
$partial = fixture('partial');
executeSql($partial, __DIR__ . '/../sql/migrations/002_daily_operations.sql');
$partial->exec("INSERT INTO kantine_task_templates VALUES ('1000-01-01','[]')");
check(strpos(executeSql($partial, $sqlFile)[0], 'STOPP') === 0, 'Refuses unversioned partial state');
check((int)$partial->query('SELECT COUNT(*) FROM kantine_schema_versions')->fetchColumn() === 0, 'No success marker for partial state');
$fault = fixture('fault');
$fault->exec("CREATE TRIGGER block_new_checks BEFORE INSERT ON kantine_values FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test failure'");
$result = executeSql($fault, $sqlFile, true);
check(in_array('SQL_ERROR', $result, true), 'Write error injected');
check(strpos(end($result), 'STOPP') === 0, 'Even an importer continuing after SQL errors reports STOPP');
check((int)$fault->query('SELECT COUNT(*) FROM kantine_day_state')->fetchColumn() === 0, 'Failed migration rolled back snapshots');
check((int)$fault->query('SELECT COUNT(*) FROM kantine_schema_versions')->fetchColumn() === 0, 'Failed migration never stamped complete');
echo "$checks SQL migration checks passed. Isolated databases: $prefix*.\n";
