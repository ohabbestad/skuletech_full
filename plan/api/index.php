<?php
declare(strict_types=1);
require __DIR__.'/domain.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
function reply(array $data,int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function encode_doc(array $d): string { return json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function decode_doc(string $d): array { return json_decode($d,true,512,JSON_THROW_ON_ERROR); }
try {
    if($_SERVER['REQUEST_METHOD']!=='POST') reply(['error'=>'Bruk POST.'],405);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>8*1024*1024) reply(['error'=>'Fila er større enn 8 MB.'],413);
    $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($input)) plan_fail('Forventa eit JSON-objekt.');
    $action=$input['action']??'';
    if(!is_file(__DIR__.'/config.local.php')) reply(['error'=>'Plandelen er ikkje sett opp på serveren enno.','setupRequired'=>true],503);
    $config=require __DIR__.'/config.local.php';
    $pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$config['db_host'],$config['db_port']??3306,$config['db_name']),$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '+00:00'");
    if($action==='public') {
        $token=$input['token']??'';
        if(!is_string($token) || !preg_match('/^[a-f0-9]{64}$/',$token)) reply(['error'=>'Lenkja er ugyldig eller utgått.'],404);
        $q=$pdo->prepare('SELECT * FROM plan_shares WHERE token_hash=? AND revoked=0 AND expires_at>UTC_TIMESTAMP()'); $q->execute([hash('sha256',$token)]); $share=$q->fetch();
        if(!$share) reply(['error'=>'Lenkja er ugyldig eller utgått.'],404);
        $row=$pdo->query('SELECT document,updated_at FROM plan_state WHERE id=1')->fetch();
        reply(['data'=>plan_public(decode_doc($row['document']),$share),'updatedAt'=>$row['updated_at']]);
    }
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
    session_name('SKULETECH_PLAN');
    $local=in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true) && ($config['allow_local_http']??false);
    if(!$local && (empty($_SERVER['HTTPS']) || strtolower((string)$_SERVER['HTTPS'])==='off')) reply(['error'=>'Innlogging krev HTTPS.'],400);
    session_set_cookie_params(['lifetime'=>0,'path'=>'/plan/','secure'=>!$local,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    if(!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
    if(isset($_SESSION['expires']) && $_SESSION['expires']<time()) { unset($_SESSION['owner'],$_SESSION['expires']); }
    if($action==='session') reply(['authenticated'=>!empty($_SESSION['owner']),'csrf'=>$_SESSION['csrf']]);
    if(!is_string($input['csrf']??null) || !hash_equals($_SESSION['csrf'],$input['csrf'])) reply(['error'=>'Økta er endra. Last sida på nytt.'],403);
    if($action==='login') {
        $password=plan_text($input['password']??'','passord',256);
        // Independent global and address buckets: bounded attempts even when requests run concurrently.
        $buckets=[hash('sha256','plan-owner-global'),hash('sha256','plan:'.($_SERVER['REMOTE_ADDR']??''))];
        $pdo->beginTransaction();
        foreach($buckets as $bucket) {
            $pdo->prepare('INSERT IGNORE INTO plan_attempts(bucket,failures,window_start) VALUES (?,0,?)')->execute([$bucket,time()]);
            $q=$pdo->prepare('SELECT * FROM plan_attempts WHERE bucket=? FOR UPDATE'); $q->execute([$bucket]); $a=$q->fetch();
            if(time()-(int)$a['window_start']>=900) $pdo->prepare('UPDATE plan_attempts SET failures=0,window_start=? WHERE bucket=?')->execute([time(),$bucket]);
            elseif((int)$a['failures']>=10) { $pdo->rollBack(); header('Retry-After: 900'); reply(['error'=>'For mange forsøk. Prøv igjen om 15 minutt.'],429); }
            $pdo->prepare('UPDATE plan_attempts SET failures=failures+1 WHERE bucket=?')->execute([$bucket]);
        }
        $pdo->commit();
        $hash=$pdo->query('SELECT password_hash FROM plan_owner WHERE id=1')->fetchColumn();
        if(!$hash || !password_verify($password,$hash)) reply(['error'=>'Feil passord.'],401);
        session_regenerate_id(true); $_SESSION['owner']=true; $_SESSION['expires']=time()+28800; $_SESSION['csrf']=bin2hex(random_bytes(32));
        foreach($buckets as $bucket) $pdo->prepare('DELETE FROM plan_attempts WHERE bucket=?')->execute([$bucket]);
        reply(['csrf'=>$_SESSION['csrf'],'authenticated'=>true]);
    }
    if(empty($_SESSION['owner'])) reply(['error'=>'Logg inn for å opne planlegginga.'],401);
    if($action==='logout') { $_SESSION=[]; session_destroy(); setcookie('SKULETECH_PLAN','',['expires'=>time()-3600,'path'=>'/plan/','secure'=>!$local,'httponly'=>true,'samesite'=>'Strict']); reply(['ok'=>true]); }
    if($action==='load') {
        $row=$pdo->query('SELECT * FROM plan_state WHERE id=1')->fetch();
        if(!$row) reply(['error'=>'Databasen manglar startdata. Følg oppsettsrettleiinga.'],503);
        $shares=$pdo->query('SELECT id,scope,target,items_json,expires_at,revoked FROM plan_shares ORDER BY created_at DESC')->fetchAll();
        $versions=$pdo->query('SELECT id,revision,label,created_at FROM plan_versions ORDER BY id DESC LIMIT 20')->fetchAll();
        reply(['document'=>decode_doc($row['document']),'revision'=>(int)$row['revision'],'shares'=>$shares,'versions'=>$versions]);
    }
    if(in_array($action,['save','restore'],true)) {
        $pdo->beginTransaction();
        $row=$pdo->query('SELECT * FROM plan_state WHERE id=1 FOR UPDATE')->fetch();
        if(($input['revision']??null)!==(int)$row['revision']) { $pdo->rollBack(); reply(['error'=>'Planen er endra i ei anna fane. Eksporter utkastet før du lastar siste lagra versjon.'],409); }
        if($action==='restore') {
            $q=$pdo->prepare('SELECT document FROM plan_versions WHERE id=?'); $q->execute([$input['versionId']??0]); $old=$q->fetchColumn();
            if(!$old) plan_fail('Versjonen finst ikkje.'); $doc=plan_validate(decode_doc($old));
        } else $doc=plan_validate($input['document']??null);
        $label=plan_text($input['label']??'Lagring','versjonsnamn',160);
        $pdo->prepare('INSERT INTO plan_versions(revision,label,document) VALUES (?,?,?)')->execute([(int)$row['revision'],$label,$row['document']]);
        $pdo->prepare('UPDATE plan_state SET document=?,revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE id=1')->execute([encode_doc($doc)]);
        // Bounded history; each operation first stores the previous state.
        $pdo->exec('DELETE FROM plan_versions WHERE id NOT IN (SELECT id FROM (SELECT id FROM plan_versions ORDER BY id DESC LIMIT 20) AS kept)');
        $pdo->commit(); reply(['revision'=>(int)$row['revision']+1,'document'=>$doc]);
    }
    if($action==='share-create') {
        $scope=plan_enum($input['scope']??'', ['lesson','day']); $target=plan_id($input['target']??'');
        $items=plan_list($input['items']??[],'valde hendingar',100);
        if(!$items) plan_fail('Vel minst éi hending.');
        $doc=decode_doc($pdo->query('SELECT document FROM plan_state WHERE id=1')->fetchColumn());
        foreach($items as $id) {
            $event=null; foreach($doc['events'] as $e) if($e['id']===$id) $event=$e;
            if(!$event || ($scope==='day' && $event['date']!==$target) || ($scope==='lesson' && ($id!==$target || $event['kind']!=='lesson'))) plan_fail('Hendinga er utanfor den valde delinga.');
        }
        if($scope==='lesson' && count($items)!==1) plan_fail('Øktdeling skal ha éi økt.');
        $expires=plan_date($input['expires']??'');
        $date=new DateTimeImmutable($expires.' 23:59:59',new DateTimeZone('Europe/Oslo'));
        if($date->getTimestamp()<=time()) plan_fail('Utløpsdato må vere i framtida.');
        $token=bin2hex(random_bytes(32)); $id=bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO plan_shares(id,token_hash,scope,target,items_json,expires_at) VALUES (?,?,?,?,?,?)')->execute([$id,hash('sha256',$token),$scope,$target,encode_doc($items),$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        reply(['id'=>$id,'token'=>$token]);
    }
    if($action==='share-update') {
        $id=plan_id($input['id']??'');
        if(!empty($input['revoke'])) $pdo->prepare('UPDATE plan_shares SET revoked=1 WHERE id=?')->execute([$id]);
        else {
            $date=new DateTimeImmutable(plan_date($input['expires']??'').' 23:59:59',new DateTimeZone('Europe/Oslo'));
            if($date->getTimestamp()<=time()) plan_fail('Utløpsdato må vere i framtida.');
            $pdo->prepare('UPDATE plan_shares SET expires_at=? WHERE id=? AND revoked=0')->execute([$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$id]);
        }
        reply(['ok'=>true]);
    }
    reply(['error'=>'Ukjend handling.'],400);
} catch(InvalidArgumentException|JsonException $e) {
    if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack(); reply(['error'=>$e->getMessage()],422);
} catch(Throwable $e) {
    if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    // Do not log request bodies, credentials, plans or share tokens.
    error_log('SkuleTech Plan: '.get_class($e)); reply(['error'=>'Tenaren kunne ikkje fullføre. Utkastet ditt er framleis ope.'],503);
}
