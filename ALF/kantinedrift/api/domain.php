<?php
declare(strict_types=1);

/** Pure rules shared by the API, migration and regression tests. */
function kv_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo'));
}

function kv_date($value): string
{
    if (!is_string($value)) throw new InvalidArgumentException('Dato manglar.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Oslo'));
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Ugyldig dato.');
    return $value;
}

function kv_text($value, int $max = 255): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
        throw new InvalidArgumentException('Feltet må innehalde gyldig tekst.');
    }
    $value = trim($value);
    if (preg_match_all('/./us', $value) > $max) throw new InvalidArgumentException("Teksten kan ha høgst $max teikn.");
    return $value;
}

function kv_names($value): array
{
    if (!is_array($value) || count($value) > 100) throw new InvalidArgumentException('Ugyldig namneliste.');
    $names = [];
    foreach ($value as $name) {
        $name = kv_text($name, 160);
        if ($name !== '' && !in_array($name, $names, true)) $names[] = $name;
    }
    return $names;
}

function kv_weekday(string $date): string
{
    return ['', 'Måndag', 'Tysdag', 'Onsdag', 'Torsdag', 'Fredag', 'Laurdag', 'Sundag'][(int)(new DateTimeImmutable($date))->format('N')];
}

function kv_monday(string $date): string
{
    return (new DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');
}

function kv_can_check(string $date, string $status, DateTimeImmutable $now): bool
{
    $local = $now->setTimezone(new DateTimeZone('Europe/Oslo'));
    return $status === 'open' && $date === $local->format('Y-m-d')
        && $local->format('H:i:s') >= '11:00:00' && $local->format('H:i:s') < '12:00:00';
}

function kv_task_rows($rows, bool $template = false): array
{
    if (!is_array($rows) || count($rows) > 250) throw new InvalidArgumentException('Ugyldig oppgåveliste.');
    $result = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('Ugyldig oppgåve.');
        $id = $row['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $id) || isset($seen[$id])) {
            throw new InvalidArgumentException('Oppgåver må ha unike, faste ID-ar.');
        }
        $label = kv_text($row['label'] ?? '');
        $shift = $row['shift'] ?? '';
        if ($label === '' || !in_array($shift, ['early', 'late'], true)) throw new InvalidArgumentException('Fyll inn oppgåve og vakt.');
        $item = ['id' => $id, 'label' => $label, 'shift' => $shift];
        if ($template) {
            if (array_key_exists('weekdays', $row)) {
                $days = $row['weekdays'];
                if (!is_array($days) || count($days) < 1 || count($days) > 5 || array_keys($days) !== range(0, count($days) - 1)) {
                    throw new InvalidArgumentException('Vel minst ein vekedag for kvar oppgåve.');
                }
                foreach ($days as $day) {
                    if (!is_int($day) || $day < 1 || $day > 5) throw new InvalidArgumentException('Vel gyldige vekedagar.');
                }
                $days = array_values(array_unique($days));
                sort($days);
                // Keep common and single-day routines compatible with existing templates.
                if (count($days) === 5) $item['weekday'] = 0;
                elseif (count($days) === 1) $item['weekday'] = $days[0];
                else $item['weekdays'] = $days;
            } else {
                $weekday = $row['weekday'] ?? null;
                if (!is_int($weekday) || $weekday < 0 || $weekday > 5) throw new InvalidArgumentException('Vel felles rutine eller ein vekedag.');
                $item['weekday'] = $weekday;
            }
        }
        $seen[$id] = true;
        $result[] = $item;
    }
    return $result;
}

function kv_tasks_for_date(array $template, string $date): array
{
    $weekday = (int)(new DateTimeImmutable($date))->format('N');
    $out = [];
    foreach ($template as $row) {
        if (isset($row['weekdays']) ? in_array($weekday, $row['weekdays'], true) : ($row['weekday'] === 0 || $row['weekday'] === $weekday)) {
            unset($row['weekday'], $row['weekdays']);
            $out[] = $row;
        }
    }
    return $out;
}

function kv_json_encode($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
