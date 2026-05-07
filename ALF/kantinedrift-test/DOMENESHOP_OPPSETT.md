# Slik testar du kantineversjonen med Domeneshop-database

Denne testversjonen ligg i `ALF/kantinedrift-test/` og påverkar ikkje dagens kantineløysing i `ALF/kantinedrift/`.

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

## 2. Last opp testmappa

Last opp heile mappa:

```txt
ALF/kantinedrift-test/
```

til same relative plass på webhotellet.

Testsidene blir då typisk:

```txt
https://skuletech.no/ALF/kantinedrift-test/laerar.html
https://skuletech.no/ALF/kantinedrift-test/driftsleiar.html
https://skuletech.no/ALF/kantinedrift-test/tilsett.html
https://skuletech.no/ALF/kantinedrift-test/infoskjerm.html
```

## 3. Lag lokal konfigurasjon på serveren

På webhotellet: kopier

```txt
ALF/kantinedrift-test/api/config.local.example.php
```

til:

```txt
ALF/kantinedrift-test/api/config.local.php
```

Fyll inn databaseinfo frå Domeneshop:

```php
return [
    'db_host' => 'localhost',
    'db_name' => 'database_namn_fra_domeneshop',
    'db_user' => 'database_brukar_fra_domeneshop',
    'db_pass' => 'database_passord_fra_domeneshop',
    'setup_key' => 'eit-langt-hemmeleg-oppsett-passord',
    'session_name' => 'KANTINE_TEST_SESS',
];
```

Bruk eit langt tilfeldig `setup_key`. Det er berre for oppsett av testpassord.

## 4. Lag tabellane

Opne phpMyAdmin eller tilsvarande databaseverktøy hos Domeneshop.

Køyr SQL-fila:

```txt
ALF/kantinedrift-test/sql/schema.sql
```

Vil du ha testdata, køyr deretter:

```txt
ALF/kantinedrift-test/sql/testdata.sql
```

## 5. Sjekk databasekoplinga

Opne:

```txt
https://skuletech.no/ALF/kantinedrift-test/api/health.php
```

Du skal få eit JSON-svar med:

```json
{"ok":true}
```

## 6. Lag passord for rollene

Opne:

```txt
https://skuletech.no/ALF/kantinedrift-test/api/setup.php
```

Skriv inn `setup_key` frå `config.local.php`, og set passord for:

- lærar/admin
- driftsleiar
- tilsett

Passorda blir lagra som hash i databasen, ikkje i klartekst.

## 7. Test appane

Start med:

```txt
https://skuletech.no/ALF/kantinedrift-test/laerar.html
```

Logg inn med lærarpassordet. Prøv deretter:

1. Opprett ei ny veke.
2. Legg inn driftsleiarar og turnus.
3. Legg inn meny.
4. Opne driftsleiar- og tilsett-sidene i eigne faner.
5. Sjekk at endringar dukkar opp på infoskjerm.

## 8. Overføring frå Google Sheet

For første test treng du ikkje importere alt frå Google Sheet. Du kan lage ei testveke i lærarsida.

Når du vil flytte ekte data:

1. Eksporter kvart relevant ark frå Google Sheet som CSV.
2. Rydd kolonnenamn slik at dei passar tabellane i `schema.sql`.
3. Importer CSV i phpMyAdmin, eller lag eigne `INSERT`-setningar.
4. Start med desse tabellane:
   - `kantine_calendar_days`
   - `kantine_settings`
   - `kantine_task_list`
   - `kantine_values`
   - `kantine_substitutes`

Ta ein kopi av databasen før du importerer ekte data.

## 9. Viktig personvern

- Ikkje bruk ekte elevdata før innlogging, roller og HTTPS er testa.
- Databasepassord skal berre liggje i `config.local.php` på serveren.
- Slett eller flytt `api/setup.php` når testen eventuelt blir produksjon.
- Bruk eigne passord for lærar, driftsleiar og tilsett.
- La infoskjermen berre hente data som kan visast offentleg.
