# Oppsett av tørrvarelager på Domeneshop

## 1. Opprett MySQL-database

Opprett ein MySQL-database hos Domeneshop og noter:

- databasenamn
- databasebrukar
- passord
- host/tenarnamn

## 2. Lag lokal konfigurasjon på serveren

Opprett fila `ALF/lager/api/config.local.php` på serveren:

```php
<?php
return [
    'db_host'      => 'localhost',
    'db_name'      => 'database_namn',
    'db_user'      => 'database_brukar',
    'db_pass'      => 'database_passord',
    'setup_key'    => 'eit-langt-hemmeleg-oppsett-passord',
    'session_name' => 'LAGER_SESS',
    'mail_enabled' => true,
    'mail_from' => 'lager@skuletech.no',
    'mail_from_name' => 'SkuleTech Tørrvarelager',
    'mail_reply_to' => '',
    'app_url' => 'https://skuletech.no/ALF/lager/dashbord.html',
    'monthly_report_token' => 'eit-langt-hemmeleg-token-for-manadsrapport',
    'timezone' => 'Europe/Oslo',
];
```

## 3. Lag tabellane

Opne phpMyAdmin og køyr SQL-fila:

```txt
ALF/lager/sql/schema.sql
```

Fila lagar tabellar og legg inn startlista med varer, kategoriar og avdelingar.

### Oppgradering av eksisterande database

Dersom tørrvarelageret allereie er sett opp på serveren, køyr denne fila éin gong i phpMyAdmin før du lastar opp ny PHP-kode:

```txt
ALF/lager/sql/upgrade-email-alerts.sql
```

Ho legg til innkjøpsansvarlege, varslingsstatus og e-postlogg.

Køyr deretter denne fila for faste mottakarar av månadsrapport:

```txt
ALF/lager/sql/upgrade-monthly-report-recipients.sql
```

## 4. Sjekk databasekoplinga

Opne:

```txt
https://skuletech.no/ALF/lager/api/health.php
```

Du skal få eit JSON-svar med `ok: true`.

Slett `api/health.php` frå serveren etter testen.

## 5. Lag passord

Opne:

```txt
https://skuletech.no/ALF/lager/api/setup.php
```

Skriv inn `setup_key` og set passord for:

- lærar/admin
- driftsleiar

Slett `api/setup.php` frå serveren etterpå.

## 6. Test flyten

1. Opne `dashbord.html`.
2. Logg inn som lærar/admin.
3. Set einingar, min-nivå og hylleplass på nokre varer.
4. Opne `etikettar.html` og skriv ut QR-etikettar.
5. Skann ein QR-kode og registrer eit uttak.
6. Sjekk at uttaket kjem i rapporten.

## 7. Test innkjøpsvarsel

1. Logg inn som lærar/admin.
2. Opne `Varer, ansvar og avdelingar`.
3. Legg inn namn og e-post på ein kategori.
4. Trykk `Test` og sjekk at e-posten kjem fram.
5. Set min-nivå på ei vare, registrer uttak frå QR-sida og sjekk e-postloggen i dashbordet.

Bruk ein avsendaradresse på same domene/webhotell som sida. Det gir betre levering og følgjer Domeneshop-reglane for e-post frå webhotell.

## 8. Set opp månadsrapport

1. Logg inn som lærar/admin.
2. Opne `Rapport`.
3. Legg inn faste mottakarar og vel avdelingane dei skal få rapport frå.
4. Set ein månadsjobb på webhotellet som kallar denne URL-en den 1. i kvar månad:

```txt
https://skuletech.no/ALF/lager/api/index.php?action=send_monthly_reports&token=DITT_HEMMELEGE_TOKEN
```

Rapporten gjeld førre månad. Dersom jobben blir kalla fleire gonger same dag, blir ikkje same mottakar sendt på nytt for same månad.
