# Kantinedrift — PHP/MySQL-backend

Kantineplanleggjar for ALF-skulen med rollebasert tilgang.

## Sider

| Side | Rolle | Tilgang |
|---|---|---|
| `laerar.html` | Lærar / Admin | Full tilgang — terminplan, bemanning, meny, fråvær |
| `driftsleiar.html` | Driftsleiar | Fråvær, meny, vikarar |
| `tilsett.html` | Tilsett | Sjekkliste (tidsavgrensa) |
| `infoskjerm.html` | Offentleg | Kantineplan for veka (ingen innlogging) |
| `infoskjerm_BUS.html` | Offentleg (TV) | Same som infoskjerm, tilpassa eldre Samsung-nettlesarar |

## Filer

- `api/index.php` — API-et alle sider snakkar med
- `api/db.php` — Database-kopling og hjelpefunksjonar
- `api/config.php` — Konfigurasjon (les frå `config.local.php` på serveren)
- `sql/schema.sql` — Databaseskjema
- `sql/testdata.sql` — Eksempeldata for testing

## Oppsett på Domeneshop

Sjå `DOMENESHOP_OPPSETT.md` for fullstendig steg-for-steg-guide.

**Viktig etter oppsett:**
- Slett `api/setup.php` frå serveren etter at passord er sett
- Slett `api/health.php` frå serveren etter at databasekoplinga er stadfesta
- Lagre aldri databasepassord i git — bruk `api/config.local.php` på serveren

## Sikkerheit

- Passord vert hasha med `password_hash()` / `password_verify()`
- Sesjonar med `httponly` og `SameSite=Lax`
- Alle databasespørjingar brukar prepared statements
- Rollebasert tilgangskontroll på alle skriveoperasjonar
