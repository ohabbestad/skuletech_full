<?php
declare(strict_types=1);
require __DIR__.'/../api/domain.php';
function check(bool $ok):void {if(!$ok)throw new RuntimeException('Test feila.');}
$e=['id'=>'fixture','date'=>'2026-11-02','kind'=>'lesson','start'=>'08:30','end'=>'10:00','allDay'=>false,'title'=>'Matematikk','subject'=>'Matematikk','group'=>'9A'];
$p=['id'=>'plan-fixture','eventId'=>'fixture','title'=>'Sannsyn','notes'=>'PRIVATE_SECRET','reflection'=>'PRIVATE_REFLECTION','status'=>'ready','activities'=>[['title'=>'Myntkast','minutes'=>30,'code'=>'print("æøå")']]];
$d=plan_empty();$d['events']=[$e];$d['plans']=[$p];$d=plan_validate($d);
$out=plan_public($d,['scope'=>'lesson','target'=>'fixture','items_json'=>'["fixture"]']);
check(!str_contains(json_encode($out),'PRIVATE'));check(count($out['events'])===1);check($out['events'][0]['plan']['activities'][0]['code']==='print("æøå")');
check(count(plan_public($d,['scope'=>'lesson','target'=>'other','items_json'=>'["fixture"]'])['events'])===0);
check(count(plan_public($d,['scope'=>'day','target'=>'2026-11-03','items_json'=>'["fixture"]'])['events'])===0);
foreach(['javascript:alert(1)','data:text/html,x'] as $url){$bad=$d;$bad['plans'][0]['resources']=[['url'=>$url,'label'=>'x']];try{plan_validate($bad);throw new RuntimeException('Farleg URL vart godteken.');}catch(InvalidArgumentException $expected){}}
$bad=$d;$bad['events'][]=$bad['events'][0];try{plan_validate($bad);throw new RuntimeException('Duplikat vart godteke.');}catch(InvalidArgumentException $expected){}
try{plan_date('2026-02-30');throw new RuntimeException('Ugyldig dato vart godteke.');}catch(InvalidArgumentException $expected){}
echo "OK: offentleg projeksjon, økt- og dagsavgrensing, kode, UTF-8, dato, URL og duplikat.\n";
