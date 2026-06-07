# Oppsett av utstyrslån på Domeneshop

## 1. Opprett MySQL-database

Opprett ein MySQL-database hos Domeneshop og noter:

- databasenamn
- databasebrukar
- passord
- host/tenarnamn

## 2. Lag lokal konfigurasjon på serveren

Opprett fila `utstyr/api/config.local.php` på serveren:

```php
<?php
return [
    'db_host'      => 'localhost',
    'db_name'      => 'database_namn',
    'db_user'      => 'database_brukar',
    'db_pass'      => 'database_passord',
    'setup_key'    => 'eit-langt-hemmeleg-oppsett-passord',
    'session_name' => 'UTSTYR_SESS',
];
```

## 3. Lag tabellane

Opne phpMyAdmin og køyr SQL-fila:

```txt
utstyr/sql/schema.sql
```

Fila lagar tabellar og legg inn startkategoriar og plasseringar.

## 4. Sjekk databasekoplinga

Opne:

```txt
https://skuletech.no/utstyr/api/health.php
```

Du skal få eit JSON-svar med `ok: true`.

Slett `api/health.php` frå serveren etter testen.

## 5. Lag passord

Opne:

```txt
https://skuletech.no/utstyr/api/setup.php
```

Skriv inn `setup_key` og set passord for:

- tilsett
- admin

Slett `api/setup.php` frå serveren etterpå.

## 6. Test flyten

1. Opne `dashbord.html`.
2. Logg inn som admin.
3. Opprett eit unikt utstyr og eit mengdeutstyr.
4. Opne `etikettar.html` og skriv ut QR-etikettar.
5. Skann ein QR-kode og registrer eit utlån med namn og frist.
6. Sjekk at utlånet er synleg i dashbordet.
7. Lever inn utstyret og sjekk at det blir ledig igjen.
