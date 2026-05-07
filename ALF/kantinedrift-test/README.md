# Kantinedrift testversjon med MySQL

Dette er ein isolert testversjon av kantine-appane. Originalen i `ALF/kantinedrift/` blir ikkje endra.

## Filer

- `laerar.html`, `driftsleiar.html`, `tilsett.html` og `infoskjerm.html` er kopiar som brukar lokal PHP-backend.
- `api/index.php` er API-et sidene snakkar med.
- `api/setup.php` lagar/byter passord for testrollene.
- `sql/schema.sql` lagar tabellane.
- `sql/testdata.sql` legg inn nokre testdata.
- `api/config.local.example.php` skal kopierast til `api/config.local.php` på webhotellet og fyllast ut med databaseinfo.

## Viktig

Ikkje legg ekte databasepassord inn i git. Bruk `config.local.php` på serveren.
