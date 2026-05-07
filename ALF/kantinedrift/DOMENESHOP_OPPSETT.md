# Oppsett av kantinedrift på Domeneshop

## 1. Opprett MySQL-database hos Domeneshop

1. Logg inn hos Domeneshop.
2. Gå til domenet ditt.
3. Opne fanen `Webhotell`.
4. Finn `MySQL` og vel `Opprett ny database`.
5. Noter:
   - databasenamn
   - databasebrukar
   - passord
   - tenarnamn/host dersom Domeneshop viser noko anna enn `localhost`

## 2. Last opp mappa

Last opp heile mappa til webhotellet. Sidene vert då typisk:

```txt
https://skuletech.no/ALF/kantinedrift/laerar.html
https://skuletech.no/ALF/kantinedrift/driftsleiar.html
https://skuletech.no/ALF/kantinedrift/tilsett.html
https://skuletech.no/ALF/kantinedrift/infoskjerm.html
https://skuletech.no/ALF/kantinedrift/infoskjerm_BUS.html
```

## 3. Lag lokal konfigurasjon på serveren

Opprett fila `api/config.local.php` på webhotellet (kopier frå `api/config.php` og fyll ut):

```php
<?php
return [
    'db_host'      => 'localhost',
    'db_name'      => 'database_namn_fra_domeneshop',
    'db_user'      => 'database_brukar_fra_domeneshop',
    'db_pass'      => 'database_passord_fra_domeneshop',
    'setup_key'    => 'eit-langt-hemmeleg-oppsett-passord',
    'session_name' => 'KANTINE_SESS',
];
```

Bruk eit langt tilfeldig `setup_key`. Det er berre for oppsett av passord.

## 4. Lag tabellane

Opne phpMyAdmin hos Domeneshop og køyr SQL-fila:

```txt
sql/schema.sql
```

## 5. Sjekk databasekoplinga

Opne `api/health.php` i nettlesaren. Du skal få:

```json
{"ok":true}
```

**Slett `api/health.php` frå serveren etter dette steget.**

## 6. Lag passord for rollene

Opne `api/setup.php` i nettlesaren. Skriv inn `setup_key` og set passord for:

- lærar/admin
- driftsleiar
- tilsett

**Slett `api/setup.php` frå serveren etter at passorda er sett.**

## 7. Test sidene

Start med `laerar.html`. Logg inn med lærarpassordet og prøv:

1. Opprett ei ny veke.
2. Legg inn driftsleiarar og turnus.
3. Legg inn meny.
4. Opne driftsleiar- og tilsett-sidene i eigne faner.
5. Sjekk at endringar dukkar opp på infoskjerm.

## 8. Viktig tryggleik

- Databasepassord skal **berre** liggje i `api/config.local.php` på serveren.
- `config.local.php` er i `.gitignore` og vert ikkje lasta opp til GitHub.
- Slett `api/setup.php` og `api/health.php` etter oppsett.
- Bruk HTTPS — krypteringa er viktig for passordsikkerheit.
- Ikkje bruk ekte elevdata utan at HTTPS er på plass.
