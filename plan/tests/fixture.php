<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('PLAN_TEST_CONFIRM')!=='isolated')exit("Set PLAN_TEST_CONFIRM=isolated.\n");
require __DIR__.'/../api/domain.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=33089;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS plan_test CHARACTER SET utf8mb4');$pdo->exec('USE plan_test');
if($pdo->query('SHOW TABLES')->fetchColumn())exit("Bruk ei ny, tom plan_test-database.\n");
foreach(explode(';',file_get_contents(__DIR__.'/../sql/schema.sql'))as$sql)if(trim($sql))$pdo->exec($sql);
$d=plan_empty();
$d['events']=[['id'=>'event-a','date'=>'2026-11-02','kind'=>'lesson','title'=>'9A Matematikk','subject'=>'Matematikk','group'=>'9A','start'=>'08:30','end'=>'10:00','allDay'=>false],['id'=>'event-b','date'=>'2026-11-02','kind'=>'lesson','title'=>'9B Naturfag','subject'=>'Naturfag','group'=>'9B','start'=>'10:10','end'=>'11:10','allDay'=>false],['id'=>'event-duty','date'=>'2026-11-02','kind'=>'duty','title'=>'Inspeksjon','start'=>'10:00','end'=>'10:10','allDay'=>false]];
$d['plans']=[['id'=>'plan-a','eventId'=>'event-a','title'=>'Sannsyn og myntkast','status'=>'ready','goals'=>'Undersøkje store tals lov.','activities'=>[['title'=>'Myntkast','minutes'=>30,'body'=>'Arbeid i par.','code'=>'print("æøå")']],'notes'=>'PRIVATE_SECRET','reflection'=>'PRIVATE_REFLECTION'],['id'=>'plan-b','eventId'=>'event-b','title'=>'HEMMELEG_ANDRE_OKT','notes'=>'PRIVATE_OTHER','status'=>'draft']];
$d=plan_validate($d);
$pdo->prepare('INSERT INTO plan_owner(id,password_hash) VALUES(1,?)')->execute([password_hash('test-only-password',PASSWORD_DEFAULT)]);
$pdo->prepare('INSERT INTO plan_state(id,document) VALUES(1,?)')->execute([json_encode($d,JSON_UNESCAPED_UNICODE)]);
file_put_contents(__DIR__.'/../api/config.local.php',"<?php\nreturn ".var_export(['db_host'=>'127.0.0.1','db_port'=>33089,'db_name'=>'plan_test','db_user'=>'root','db_pass'=>'','allow_local_http'=>true],true).";\n");
echo "Isolert plan_test klar.\n";
