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
];
```

## 3. Lag tabellane

Opne phpMyAdmin og køyr SQL-fila:

```txt
ALF/lager/sql/schema.sql
```

Fila lagar tabellar og legg inn startlista med varer, kategoriar og avdelingar.

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
