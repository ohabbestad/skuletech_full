# Kantinedrift — PHP/MySQL-backend

Kantineplanleggjar for ALF-skulen med rollebasert tilgang.

## KantineVeke 2: dagleg drift

Dei innlogga sidene deler no `assets/app.js`, `assets/model.js` og lokal CSS. Dei treng ikkje Tailwind-CDN eller eit frontend-bygg. Eksisterande adresser og rollepassord er vidareførte.

- Lærar: **Dagens drift**, **Planlegging**, **Oppsett**. Grunnturnus A/B/C, faste driftsleiarar, felles oppgåver og vekedagsoppgåver blir styrte under Oppsett.
- Driftsleiar: dagens drift og planoversikt. Kan registrere fråvær, meny og vikarar, men ikkje endre grunnplan, dagsbemanning eller oppgåver.
- Tilsett: sjekklister først. Begge vakter er opne for avkryssing frå **11.00 til før 12.00**, berre på ein open arbeidsdag i `Europe/Oslo`. Læraren kan korrigere utanfor dette tidsrommet.
- Dagsbemanning og dagslister kan tilpassast utan å endre malane. Ei tom vakt er eit eksplisitt val; «Bruk grunnturnus igjen» gjenopprettar arv frå malen.
- Oppgåvemalar får verknad frå neste dag. Dagens liste, historikk og særskilt tilpassa framtidige dagslister blir ikkje omskrivne. Nye veker får malen som gjeld på kvar dato.
- Endra grunnbemanning gjeld framtidige dagar som følgjer turnusen. Dagens og eldre bemanning blir frosen før endringa.
- Å fjerne ei veke tek dagane ut av kalenderen. Meny, registreringar og dagskopiar blir bevarte og tekne i bruk dersom veka blir oppretta igjen.

## Oppgradering av eksisterande database

**Ikkje publiser dei nye sidene før migreringa er klar.** Automatisk deploy migrerer ikkje databasen. GitHub-flyten held migreringsverktøy, SQL og testar utanfor den offentlege publiseringa.

### Utan SSH: phpMyAdmin og FileZilla

Bruk den fullstendige SQL-migreringa `sql/migrations/002_phpmyadmin.sql`, ikkje berre tabelloppsettet i `002_daily_operations.sql`. Ho er laga for MySQL 5.7.8+ / MariaDB 10.2.3+ og treng ikkje lagra prosedyrar. Ho legg til tabellar, kopierer dagslister og gamle avkryssingar, kontrollerer radene før versjon 2 blir registrert og rullar tilbake datakopieringa ved manglande samsvar. Ei ny køyring etter fullført oppgradering endrar ikkje data. Delvis, uversjonert innhald i dei nye tabellane blir avvist for manuell vurdering.

`upgrade/LES-MEG.txt` forklarer rekkjefølgja: mellombels vedlikehaldsfil → SQL-import → nye HTML-/ressurs-/hjelpefiler → ordinær API-fil til slutt. Det stoppar også skriving frå gamle nettlesarøkter medan oppgraderinga går. Ta ny databaseeksport etter stenging dersom data har endra seg sidan førre sikkerheitskopi.

Bygg ei avgrensa opplastingspakke med `upgrade/build-package.ps1`. Pakka blir lagd i den Git-ignorerte mappa `codex-temp-kantine-oppgradering`. Ho inneheld aldri `config.local.php`, sikkerheitskopiar, testdata eller oppsett-/diagnoseendepunkt. Følg instruksjonane i pakka; ikkje last opp heile ZIP-innhaldet til webrota.

SQL-migreringa bevarer opphavlege namnefelt som JSON-lister. Ved lesing blir listene normaliserte med same trim-/duplikatreglar som PHP-migreringa. `tests/phpmyadmin-migration.php` samanliknar begge metodane på isolerte testdatabasar, inkludert UTF-8, gamle indeksnøklar, omkøyring, delvis tilstand og tilbakeføring når ein importør held fram etter SQL-feil. Set `KANTINE_TEST_CONFIRM=isolated` og bruk den portable test-PHP-en frå `codex-temp-kantine/php` med PDO MySQL aktivert.

### Med PHP frå kommandolinja

1. Avtal eit kort vedlikehaldsvindauge og stopp skriving frå gamle klientar. Ta ein full databaseeksport og kopi av eksisterande kantinefiler. Oppbevar sikkerheitskopien utanfor offentleg webrot.
2. Prøv oppgraderinga mot ei isolert kopi først. Ta vare på radtal og relevante menyar, bemanningsfelt og avkryssingar før/etter. Testdata skal ikkje innehalde elevopplysningar.
3. Legg migreringsverktøyet og avhengigheitene i ei privat mappe på ein maskin med PHP 8.0+ og PDO MySQL. Behald katalogforholdet `api/` og `sql/migrations/`. Bruk ein lokal `api/config.local.php` for databasen som skal oppgraderast; valfri `db_port` har standard 3306.
4. Køyr `php api/migrate.php --backup-confirmed`. Skriptet kan berre køyrast frå kommandolinja. Det opprettar fire tabellar og kopierer oppgåvemalar, dagslister og gamle avkryssingar i ein transaksjon. Eksisterande tabellar blir bevarte. DDL er idempotent; MySQL kan ikkje rulle tilbake DDL, men ei avbroten datakopiering kan køyrast på nytt.
5. Køyr kommandoen på nytt og kontroller at han melder «allereie installert». Versjon 2 skal finnast i `kantine_schema_versions`.
6. Publiser dei tre innlogga sidene, `assets/` og API-filene samla medan vedlikehaldet varer. Behald produksjonen sin `config.local.php`. Kontroller alle roller og begge infoskjermar, og be aktive brukarar laste sida på nytt før drifta opnar.
7. Ved feil: hald skriving stengd og gjenopprett både databaseeksporten og dei gamle filene. Etter at ny drift er opna, må nye registreringar takast vare på før eventuell tilbakeføring; gamle klientar forstår ikkje dei nye dagsoppgåvene.

Ved nyinstallasjon: køyr først `sql/schema.sql`, opprett rollepassorda etter den eksisterande oppsettsrettleiinga og køyr deretter same migrering.

## API og samtidige endringar

`POST api/index.php` bruker JSON med `role` og `action` (`load`, `save`, `logout`). Innlogging legg til `password`. `load` for innlogga roller returnerer kalender med ferdig berekna bemanning og dagsoppgåver, menyar, fråvær, malar, norsk serverdato/-tid og `revision`.

Alle nye skriveoperasjonar krev `revision` frå versjonen brukaren redigerer. Ein felles databaselås serialiserer skriving; foreldra revisjon gir HTTP 409 utan delvis lagring. Dette er medvite konservativt: også endringar andre stader i planen kan utløyse ein konflikt. Grensesnittet bevarer utkastet, viser siste lagra verdiar for samanlikning og krev ei uttrykkeleg ny lagring. Dei gamle skriveformatane blir avviste.

| `type` | Felt i tillegg til `revision` | Rolle |
|---|---|---|
| `task_check` | `dateId`, `key` (stabil oppgåve-ID), `value` (boolsk) | Tilsett innanfor tidsrommet, lærar |
| `attendance` | `dateId`, `name`, `value` (til stades) | Driftsleiar, lærar |
| `menu_day` | `dateId`, `value`, `allergens`, `alternative`, `alternativeAllergens`, `alternativeFree` | Driftsleiar, lærar |
| `vikar_add`, `vikar_remove` | `dateId`, `name`, `shift` (`tidleg`/`seint`) | Driftsleiar, lærar |
| `day_staffing` | `dateId`, `leader`, `early`/`late` (namnelister), eller `reset: true` | Lærar |
| `day_tasks` | `dateId`, `rows`, eller `reset: true` | Lærar |
| `task_templates` | `rows`: `id`, `label`, `shift` (`early`/`late`), `weekday` (0=felles, 1–5=vekedag) | Lærar |
| `calendar_day` | `dateId`, `status` (`open`/`closed`), `note` | Lærar |
| `create_week` | `monday`, `turnus` (`A`/`B`/`C`/`Ferie`) | Lærar |
| `delete_week` | `monday`, aldri berre vekenummer | Lærar |
| `staff_templates` | `items` med alle 35 `key`/`value`-felta | Lærar |

Dagsoppgåver bruker same radformat som malen, utan `weekday`. Oppgåve-ID blir bevart ved redigering og omordning. Avkryssingar for fjerna oppgåver blir bevarte i databasen. Infoskjermrolla får berre kalenderfelta skjermane bruker og menydata; bemanningsnamn, fråvær og sjekklister blir ikkje utleverte. Som før viser infoskjermane merknaden når ein dag er stengd. Grensesnittet opplyser om dette ved redigering; merknader for opne dagar er berre tilgjengelege etter innlogging.

## Kontroll og testar

Faste oppgåver kan ha fleire vekedagar, valde med avkryssingsfelt. Eldre malar med éin dag eller «Alle dagar» blir lesne som før. Dagvala blir lagra i eksisterande JSON-felt, så denne endringa krev inga ny SQL-migrering. Endringar gjeld framleis frå neste dag. `tests/weekdays.test.mjs` kontrollerer lagring, nye veker, tilpassa lister, historikk og avkryssingar mot det isolerte testmiljøet.

Enkle testar: `php tests/domain.php` og `node --test tests/model.test.mjs` frå kantinemappa. PHP-testen kontrollerer mellom anna klokka 10.59.59, 11.00.00, 11.59.59 og 12.00.00, sommar-/vintertid, stengde dagar, vekedagar, årsskifte og stabile ID-ar.

Integrasjonstesting krev ein **isolert** MySQL/MariaDB på loopback, ei ny tom database kalla `kantine_test`, og ein eigen kopi av kantinemappa med testkonfigurasjon. Ingen av testane skal køyrast mot produksjonen.

1. Set miljøvariabelen `KANTINE_TEST_CONFIRM=isolated`. `tests/fixture.php` bruker port 33079 og brukar `root` som standard; overstyr med `KANTINE_TEST_PORT`, `KANTINE_TEST_USER`, `KANTINE_TEST_PASSWORD` ved behov. Køyr fixture mot den tomme databasen.
2. Set testkopien sin `api/config.local.php` til denne databasen. Køyr migreringa to gonger og start den lokale PHP-tenaren med testkopien som webrot.
3. Set `KANTINE_TEST_URL=http://127.0.0.1:8765`. Køyr `node tests/api.test.mjs`. Fixture-passordet er `test-only` for alle tre roller.
4. Installer Playwright i testmiljøet, eller set `PLAYWRIGHT_MODULE` til ei eksisterande installering. Køyr `node tests/browser.test.mjs`. Nettlesaren er Edge som standard; `KANTINE_BROWSER` kan overstyre kanalen. Skjermbilete blir lagra under `codex-temp-kantine/screenshots` eller `KANTINE_SCREENSHOTS`.

Nettlesartesten sjekkar 320, 375, 768, 1024 og 1440 CSS-pikslar for alle roller, ombrekking tilsvarande 200 prosent zoom, dialogar, bevarte utkast ved nettverksfeil, konfliktløysing, tom bemanning og begge infoskjermane. Ho endrar berre testdata. Eldre Samsung-maskinvare må i tillegg røynsleprøvast ved innføring; skjermfilene er ikkje endra.

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
- Sesjonar med `httponly`, `SameSite=Lax`, nettlesar-cookie og serverstyrt levetid
- Alle databasespørjingar brukar prepared statements
- Rollebasert tilgangskontroll på alle skriveoperasjonar
