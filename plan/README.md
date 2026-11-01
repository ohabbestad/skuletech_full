# SkuleTech Plan

Personleg vekeplanlegging med separat eigarinnlogging, ICS-import, øktplanar, malar, gjeremål og avgrensa, opne leselenkjer. PHP 8.1+ med PDO MySQL, MySQL 5.7+/MariaDB 10.2+, InnoDB og HTTPS. Ingen nye eksterne tenester eller nettlesaravhengigheiter.

## Oppsett på Domeneshop

1. Opprett ei **ny, tom database** i Domeneshop. Noter servernamn, databasenamn og brukar. Hald eksisterande kantinedatabase utanfor oppsettet.
2. Køyr `tools/lag-oppsett.ps1` lokalt med `-PhpPath` til PHP, `-SeedPath` til den private startfila og `-OutputPath` til ei privat mappe utanfor repo/webrot. Skriptet spør etter databasepassord og nytt eigarpassord utan å vise dei. Utelet du SeedPath, får du eit tomt arkiv. Skriptet legg ikkje passord i kommandolinja.
3. Opne phpMyAdmin for den nye databasen og importer den genererte `database.sql`. Dette er berre for første oppsett; ikkje importer startfila på nytt mot ein database som er i bruk. Alle tabellnamn startar med `plan_`.
4. Last opp den genererte `config.local.php` til `www/plan/api/config.local.php` med den vanlege filtilkoplinga di. Denne fila skal berre liggje på serveren. Ho blir ikkje publisert frå GitHub.
5. Publiser appfilene til `www/plan/`. Ta med `.htaccess` i plandelen og API-mappa. Hald `tools/`, `sql/`, `tests/`, README, kalenderar, eksportar og startdata utanfor offentleg webrot. GitHub-workflowen ekskluderer desse.
6. Opne `https://skuletech.no/plan/`, logg inn og kontroller veke 45. Prøv ei øktlenkje i eit privat nettlesarvindauge, og slå henne av etter testen. Kontroller også at `plan/api/config.local.php` og `plan/api/domain.php` ikkje kan hentast over nettet.
7. Oppbevar oppsettsfilene privat fram til innlogging og startdata er kontrollerte. Fjern deretter lokale kopiar med databasepassord som du ikkje treng. SQL-fila inneheld private planar og passordhash.

Ingen offentleg installasjons- eller passordendringsside blir publisert. For å byte gløymt passord: lag ny hash lokalt med PHP `password_hash`, og oppdater berre `plan_owner.password_hash` for id 1 i phpMyAdmin. Logg ut aktive økter / vent maksimalt åtte timar ved passordbyte.

## Data og lagring

`plan_state` lagrar eit validert JSON-dokument og ein revisjon. Skriving låser denne rada og krev rett revisjon. Den førre versjonen blir lagra i `plan_versions` i same transaksjon; dei 20 siste blir bevarte. Konflikt gir HTTP 409. Full JSON-eksport gir ekstra sikkerheitskopi; bruk databaseeksport dersom aktive delingar også skal bevarast. Før ei seinare oppgradering: steng skriving, ta databasekopi og kontroller tilbakeføring før nye filer blir publiserte.

Alle API-operasjonar brukar POST mot `api/index.php`. `session` gir CSRF-nøkkel, `login` gir ei ny nøkkel, og `load`, `save`, `restore`, `share-create`, `share-update` og `logout` krev eigarøkt og CSRF. `public` tek berre ein delingsnøkkel og returnerer ein uttrykkeleg tillaten leseprojeksjon. Serveren tek aldri imot klientvalde ID-ar til å utvide ei offentleg deling. Øktinformasjonskapselen heiter `SKULETECH_PLAN`, har sti `/plan/`, er HttpOnly, SameSite Strict og Secure i produksjon. Økta varer høgst åtte timar.

Delingsnøklar har 256 bit tilfeldig innhald, blir lagra som SHA-256 og blir berre returnerte éin gong. Lenkja brukar URL-fragment (`share.html#…`) slik at nøkkelen ikkje kjem i vanlege URL-loggar eller referrarar. Utløp er ved slutten av vald dato i Europe/Oslo. Ei erstatningslenkje blir oppretta før gammal lenkje blir tilbakekalla. Deling bevarer eit eksplisitt utval av hendingar; nye økter blir ikkje delte automatisk. Flytting av ei hending til ein annan dag tek henne ut av ei tidlegare dagsdeling. Avlysingsstatus blir vist.

## JSON-format, versjon 1

Rotobjektet har `schemaVersion: 1`, og listene `events`, `plans`, `templates`, `todos`. `meta` kan opplyse om eksporttype og `weekStart`, men er ikkje ei datakjelde på serveren. Ein vekeeksport inneheld berre hendingar, kopla planar og gjeremål i den veka. Full eksport tek også med ufordelte utkast og malar. Innloggingsopplysningar og delingsnøklar blir aldri eksporterte.

* `events`: stabil `id`, `date` (ÅÅÅÅ-MM-DD), `kind` (lesson/duty/meeting/day/message), `title`, `subject`, `group`, `allDay`, `start`/`end` (TT:MM eller tomme ved heildag), `weekType` (A/B/C/tomt), `cancelled`, `cancelReason`, `source`, `uid`, `missing`, `override`, `calendarStamp`.
* `plans`: stabil `id`, `eventId` (kalender-ID eller null for ufordelt), `status` (unplanned/draft/ready/done), `title`, `subject`, `group`, `goals`, `equipment`, `preparation`, `activities`, `differentiation`, `assessment`, `resources`, `notes`, `reflection`. Aktivitetar har `title`, heiltalsfeltet `minutes`, `body` og `code`. Ressursar har `label` og HTTP(S)-`url`.
* `templates`: `id`, `label`, `content`. Content har berre undervisningsfelta frå ein plan, utan ID, øktkopling, status, private notat og refleksjon.
* `todos`: `id`, `date`, `eventId` (eller null), `text`, `done`.

Ved samtaleimport: eksporter veka, bevar ID-ane, og legg inn dei nye undervisningsfelta. Ny ID tyder nytt innhald. Berre eitt opplegg kan vere kopla til ei kalenderøkt. Import med ein ukjend eventId krev manuell kopling eller ufordelt utkast. Import er ei samanslåing: data som ikkje er nemnde, blir bevarte. Ta full eksport før store endringar.

ICS-import støttar konkrete VEVENT med UID, DTSTART/DTEND, lokal tid i Europe/Oslo eller UTC, heildag på éin dato, bretta linjer og ICS-tekstescaping. Gjentakingsreglar, andre tidssoner og fleirdagshendingar blir avviste med forklaring. Dei vedlagde kontrollerte kalenderane har konkrete datoar og kan importerast direkte. Bruk alltid same kjeldeval (timeplan/inspeksjon) ved ny import. Originalkalenderen blir ikkje lasta opp eller lagra som offentleg fil.

## Lokal kontroll

`node --test plan/tests/model.test.mjs`, PHP-testen `tests/domain.php`, og integrasjonstesten `tests/integration.mjs` kontrollerer kalender, personvern, tilgang, konflikt og tilbakeføring. Integrasjonstesten krev ein isolert database `plan_test`, lokal PHP-tenar og testpassordet frå testfixture. Bruk aldri produksjonskonfigurasjon til testar. Nettlesartesten brukar Playwright lokalt med Chromium/Edge og fiktive data.
