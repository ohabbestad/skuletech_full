<?php
declare(strict_types=1);
// CLI-only fixture. Does not read the production config or use real names.
if (PHP_SAPI !== 'cli' || getenv('KANTINE_TEST_CONFIRM') !== 'isolated') exit("Set KANTINE_TEST_CONFIRM=isolated.\n");
$port = (int)(getenv('KANTINE_TEST_PORT') ?: 33079);
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;dbname=kantine_test;charset=utf8mb4", getenv('KANTINE_TEST_USER') ?: 'root', getenv('KANTINE_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($pdo->query('SHOW TABLES')->fetchColumn()) exit("Use a new, empty kantine_test database.\n");
foreach (explode(';', file_get_contents(__DIR__.'/../sql/schema.sql')) as $statement) if (trim($statement)) $pdo->exec($statement);
$hash = password_hash('test-only', PASSWORD_DEFAULT);
foreach (['laerar','driftsleiar','tilsett'] as $role) $pdo->prepare('INSERT INTO kantine_users(username,role,password_hash) VALUES (?,?,?)')->execute([$role,$role,$hash]);
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Oslo'));
$dates = [];
for ($i=-7;$i<15;$i++) $dates[]=$today->modify("$i days");
$dates[]=new DateTimeImmutable('2025-12-29'); $dates[]=new DateTimeImmutable('2026-12-28');
foreach ($dates as $date) $pdo->prepare('INSERT IGNORE INTO kantine_calendar_days(date_id,week_no,turnus_type,status) VALUES (?,?,?,?)')->execute([$date->format('Y-m-d'),(int)$date->format('W'),'A','open']);
foreach (['Måndag','Tysdag','Onsdag','Torsdag','Fredag'] as $day) {
    $items=['driftsleiar_'.$day=>'Leiar A'];
    foreach (['A','B','C'] as $turnus) foreach (['tidleg','seint'] as $shift) $items["turnus_{$turnus}_{$day}_{$shift}"]=$shift==='tidleg'?'Elev A, Elev B':'Elev C, Elev D';
    foreach($items as $key=>$value) $pdo->prepare('INSERT INTO kantine_settings(setting_key,setting_value) VALUES (?,?)')->execute([$key,$value]);
}
$pdo->prepare("INSERT INTO kantine_values(value_kind,date_id,item_key,value_bool) VALUES ('tasks',?,'early_0',1)")->execute([$today->format('Y-m-d')]);
$pdo->prepare("INSERT INTO kantine_values(value_kind,date_id,item_key,value_text) VALUES ('menus',?,'dagens','Grønsakssuppe med rundstykke')")->execute([$today->format('Y-m-d')]);
echo "Isolated fixture ready. Run migration twice, then API and browser tests.\n";
