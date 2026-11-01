<?php
declare(strict_types=1);

function plan_empty(): array {
    return ['schemaVersion'=>1, 'events'=>[], 'plans'=>[], 'templates'=>[], 'todos'=>[]];
}
function plan_fail(string $message): never { throw new InvalidArgumentException($message); }
function plan_text(mixed $v, string $field, int $limit=50000): string {
    if (!is_string($v) || strlen($v)>$limit || !preg_match('//u', $v)) plan_fail("Ugyldig tekst i $field.");
    return $v;
}
function plan_id(mixed $v): string {
    $v=plan_text($v, 'ID', 250);
    if ($v==='' || preg_match('/[\x00-\x1f]/', $v)) plan_fail('ID manglar eller inneheld kontrollteikn.');
    return $v;
}
function plan_date(mixed $v): string {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) plan_fail('Dato må vere ÅÅÅÅ-MM-DD.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d')!==$v) plan_fail('Datoen finst ikkje.');
    return $v;
}
function plan_time(mixed $v): string {
    if (!is_string($v) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $v)) plan_fail('Klokkeslett må vere TT:MM.');
    return $v;
}
function plan_list(mixed $v, string $field, int $max=3000): array {
    if (!is_array($v) || !array_is_list($v) || count($v)>$max) plan_fail("Ugyldig liste: $field.");
    return $v;
}
function plan_enum(mixed $v, array $choices): string {
    if (!is_string($v) || !in_array($v,$choices,true)) plan_fail('Ukjend type eller status.');
    return $v;
}
function plan_bool(mixed $v): bool {
    if (!is_bool($v)) plan_fail('Forventa sann/usann.');
    return $v;
}
function plan_content(array $p): array {
    $out=[];
    foreach (['title','subject','group','goals','equipment','preparation','differentiation','assessment'] as $k)
        $out[$k]=plan_text($p[$k]??'', $k);
    $out['activities']=[];
    foreach(plan_list($p['activities']??[], 'aktivitetar', 100) as $a) {
        if (!is_array($a)) plan_fail('Ugyldig aktivitet.');
        $min=$a['minutes']??0;
        if (!is_int($min) || $min<0 || $min>600) plan_fail('Aktivitetstid må vere 0–600 minutt.');
        $out['activities'][]=['title'=>plan_text($a['title']??'','aktivitet'), 'minutes'=>$min,
            'body'=>plan_text($a['body']??'','aktivitetstekst'), 'code'=>plan_text($a['code']??'','kode',100000)];
    }
    $out['resources']=[];
    foreach(plan_list($p['resources']??[], 'ressursar',100) as $r) {
        if(!is_array($r)) plan_fail('Ugyldig ressurs.');
        $url=plan_text($r['url']??'','URL',2000);
        if (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true))
            plan_fail('Ressurslenkjer må starte med https:// eller http://.');
        $out['resources'][]=['label'=>plan_text($r['label']??'','ressursnamn',500),'url'=>$url];
    }
    return $out;
}
function plan_validate(mixed $doc): array {
    if (!is_array($doc) || ($doc['schemaVersion']??null)!==1) plan_fail('Fila må ha schemaVersion: 1.');
    $out=plan_empty(); $ids=[]; $eventIds=[]; $assigned=[];
    foreach(plan_list($doc['events']??null,'kalender',5000) as $e) {
        if(!is_array($e)) plan_fail('Ugyldig kalenderhending.');
        $id=plan_id($e['id']??null);
        if(isset($ids[$id])) plan_fail('Duplikat av ID.'); $ids[$id]=true; $eventIds[$id]=true;
        $x=['id'=>$id,'date'=>plan_date($e['date']??null), 'kind'=>plan_enum($e['kind']??'', ['lesson','duty','meeting','day','message']),
            'allDay'=>plan_bool($e['allDay']??false)];
        $x['start']=$x['allDay']?'':plan_time($e['start']??'');
        $x['end']=$x['allDay']?'':plan_time($e['end']??'');
        if(!$x['allDay'] && $x['end']<=$x['start']) plan_fail('Slutt må vere etter start same dag.');
        foreach(['title','subject','group','source','uid','cancelReason'] as $k) $x[$k]=plan_text($e[$k]??'', $k,2000);
        $x['weekType']=plan_enum($e['weekType']??'', ['','A','B','C']);
        foreach(['cancelled','missing','override'] as $k) $x[$k]=plan_bool($e[$k]??false);
        // A fingerprint of calendar input, not an alternative source of display/private data.
        $x['calendarStamp']=plan_text($e['calendarStamp']??'','kalenderfingeravtrykk',10000);
        $out['events'][]=$x;
    }
    foreach(plan_list($doc['plans']??null,'planar',5000) as $p) {
        if(!is_array($p)) plan_fail('Ugyldig øktplan.');
        $id=plan_id($p['id']??null);
        if(isset($ids[$id])) plan_fail('Duplikat av ID.'); $ids[$id]=true;
        $ev=$p['eventId']??null;
        if($ev!==null && (!is_string($ev) || !isset($eventIds[$ev]) || isset($assigned[$ev]))) plan_fail('Øktkopling manglar eller er brukt to gonger.');
        if($ev!==null) $assigned[$ev]=true;
        $x=plan_content($p); $x['id']=$id; $x['eventId']=$ev;
        $x['status']=plan_enum($p['status']??'draft',['unplanned','draft','ready','done']);
        foreach(['notes','reflection'] as $k) $x[$k]=plan_text($p[$k]??'', $k);
        $out['plans'][]=$x;
    }
    foreach(plan_list($doc['templates']??[], 'malar',1000) as $t) {
        $id=plan_id($t['id']??null);
        if(isset($ids[$id])) plan_fail('Duplikat av ID.'); $ids[$id]=true;
        $out['templates'][]=['id'=>$id,'label'=>plan_text($t['label']??'','malnamn',200),'content'=>plan_content($t['content']??[])];
    }
    foreach(plan_list($doc['todos']??[], 'gjeremål',5000) as $t) {
        $id=plan_id($t['id']??null);
        if(isset($ids[$id])) plan_fail('Duplikat av ID.'); $ids[$id]=true;
        $ev=$t['eventId']??null;
        if($ev!==null && !isset($eventIds[$ev])) plan_fail('Gjeremålet viser til ei økt som manglar.');
        $out['todos'][]=['id'=>$id,'date'=>plan_date($t['date']??''),'eventId'=>$ev,
            'text'=>plan_text($t['text']??'','gjeremål',2000),'done'=>plan_bool($t['done']??false)];
    }
    return $out;
}
function plan_public(array $doc, array $share): array {
    $items=json_decode($share['items_json'],true,512,JSON_THROW_ON_ERROR);
    $events=[];
    foreach($doc['events'] as $e) {
        if(!in_array($e['id'],$items,true)) continue;
        if($share['scope']==='lesson' && ($e['id']!==$share['target'] || $e['kind']!=='lesson')) continue;
        if($share['scope']==='day' && $e['date']!==$share['target']) continue;
        $row=array_intersect_key($e,array_flip(['id','date','kind','title','subject','group','start','end','allDay','cancelled','cancelReason']));
        $row['plan']=null;
        foreach($doc['plans'] as $p) if($p['eventId']===$e['id']) { $row['plan']=plan_content($p); break; }
        $events[]=$row;
    }
    usort($events,fn($a,$b)=>strcmp($a['date'].($a['start']?:'00:00'),$b['date'].($b['start']?:'00:00')));
    return ['scope'=>$share['scope'],'target'=>$share['target'],'events'=>$events];
}
