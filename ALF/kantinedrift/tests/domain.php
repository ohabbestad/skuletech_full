<?php
declare(strict_types=1);
require __DIR__ . '/../api/domain.php';
$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function rejects(callable $fn, string $label): void {
    try { $fn(); } catch (InvalidArgumentException $e) { check(true, $label); return; }
    check(false, $label);
}
$tz = new DateTimeZone('Europe/Oslo');
foreach (['10:59:59' => false, '11:00:00' => true, '11:59:59' => true, '12:00:00' => false] as $time => $allowed) {
    check(kv_can_check('2026-09-16', 'open', new DateTimeImmutable("2026-09-16 $time", $tz)) === $allowed, "Time boundary $time");
}
check(kv_can_check('2026-09-16', 'open', new DateTimeImmutable('2026-09-16T09:30:00Z')), 'Oslo summer time');
check(kv_can_check('2026-01-16', 'open', new DateTimeImmutable('2026-01-16T10:30:00Z')), 'Oslo winter time');
check(!kv_can_check('2026-09-16', 'closed', new DateTimeImmutable('2026-09-16 11:30', $tz)), 'Closed day');
check(!kv_can_check('2026-09-15', 'open', new DateTimeImmutable('2026-09-16 11:30', $tz)), 'Wrong date');
check(kv_monday('2027-01-01') === '2026-12-28', 'Week spans year boundary');
check(kv_monday('2026-01-02') !== kv_monday('2027-01-01'), 'Week number is not an identity');
rejects(fn() => kv_date('2026-02-30'), 'Invalid day');
rejects(fn() => kv_date('2026-1-1'), 'Strict ISO date');
rejects(fn() => kv_text(str_repeat('ø', 256)), 'Unicode length');
check(kv_text('ÆØÅ «nynorsk»') === 'ÆØÅ «nynorsk»', 'UTF-8');
check(kv_names(['Elev A', '', 'Elev A', 'Elev B']) === ['Elev A', 'Elev B'], 'Names normalize');
$rows = kv_task_rows([
    ['id'=>'common','label'=>'Felles','shift'=>'early','weekday'=>0],
    ['id'=>'tuesday','label'=>'Varemottak','shift'=>'late','weekday'=>2],
    ['id'=>'wednesday','label'=>'Matførebuing','shift'=>'early','weekday'=>3],
], true);
check(array_column(kv_tasks_for_date($rows, '2026-09-15'), 'id') === ['common', 'tuesday'], 'Tuesday routines');
check(array_column(kv_tasks_for_date($rows, '2026-09-16'), 'id') === ['common', 'wednesday'], 'Wednesday routines');
check(array_column(kv_tasks_for_date(array_reverse($rows), '2026-09-16'), 'id') === ['wednesday', 'common'], 'Reorder preserves identity');
rejects(fn() => kv_task_rows([$rows[0], $rows[0]], true), 'Duplicate identity');
rejects(fn() => kv_task_rows([['id'=>'x','label'=>'','shift'=>'early']]), 'Empty label');
rejects(fn() => kv_task_rows([['id'=>'x','label'=>'Task','shift'=>'night']]), 'Unknown shift');
$multi = kv_task_rows([['id'=>'multi','label'=>'Vask','shift'=>'late','weekdays'=>[5,1,3,3]]], true);
check($multi[0]['weekdays'] === [1,3,5], 'Several weekdays sorted and deduplicated');
foreach (['2026-09-14'=>true, '2026-09-15'=>false, '2026-09-16'=>true, '2026-09-17'=>false, '2026-09-18'=>true] as $date => $included) {
    $daily = kv_tasks_for_date($multi, $date);
    check(count($daily) === ($included ? 1 : 0), "Multiple weekdays on $date");
    if ($included) check($daily[0] === ['id'=>'multi','label'=>'Vask','shift'=>'late'], 'Daily copy keeps identity without recurrence fields');
}
foreach ([[], [0], [6], ['1'], [1.5], null, '1,3', ['day'=>1]] as $invalid) {
    rejects(fn() => kv_task_rows([['id'=>'x','label'=>'Vask','shift'=>'early','weekdays'=>$invalid]], true), 'Invalid weekday selection');
}
check(kv_task_rows([['id'=>'x','label'=>'Vask','shift'=>'early','weekdays'=>[1,2,3,4,5]]], true)[0]['weekday'] === 0, 'All weekdays keep common routine');
check(kv_task_rows([['id'=>'x','label'=>'Vask','shift'=>'early','weekdays'=>[3]]], true)[0]['weekday'] === 3, 'Single weekday remains compatible');
echo "$checks domain checks passed.\n";
