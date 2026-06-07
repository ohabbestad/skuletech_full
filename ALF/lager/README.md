# Tørrvarelager

Lagerstyring for felles tørrvarelager i arbeidslivsfag/skulekvardag.

## Sider

| Side | Bruk | Tilgang |
|---|---|---|
| `vare.html?vare=qr-kode` | Registrere inn/ut frå QR-kode på hyllekant | Open |
| `dashbord.html` | Lageroversikt, vareteljing, rapport, innkjøpsvarsel og administrasjon | Passord |
| `etikettar.html` | Utskrift av QR-etikettar | Passord |

## Rollar

- `driftsleiar`: lageroversikt, vareteljing og rapport.
- `laerar`: alt driftsleiar kan, pluss redigering av varer og avdelingar.

## Filer

- `api/index.php` - API for QR-side og dashbord
- `api/db.php` - databasekopling og hjelpefunksjonar
- `api/config.php` - standardkonfigurasjon, les `config.local.php` på server
- `api/setup.php` - opprett/byt passord etter databaseoppsett
- `api/health.php` - sjekk databasekopling
- `sql/schema.sql` - tabellar og frødata frå varelista
- `sql/upgrade-email-alerts.sql` - oppgradering for eksisterande database med e-postvarsel

## Innkjøpsvarsel

Lærar/admin kan leggje inn innkjøpsansvarleg per kategori og eventuelt overstyre ansvarleg på enkeltvarer. Når ei aktiv vare med min-nivå kjem på eller under min-nivå, sender serveren e-post til rett ansvarleg. Det blir berre sendt eitt varsel per låg-lager-periode. Når vara blir fylt over min-nivå igjen, blir varslingsstatus nullstilt.

E-postsending krev at `mail_enabled` er sett til `true` i `api/config.local.php` på serveren, og at `mail_from` er ei gyldig avsendaradresse for domenet/webhotellet.

## Viktig etter oppsett

- Slett `api/setup.php` frå serveren når passorda er sett.
- Slett `api/health.php` frå serveren når databasekoplinga er testa.
- Lagre aldri databasepassord i git. Bruk `api/config.local.php` på serveren.

## Frødata

Systemet legg inn varelista frå `Basisvarer.docx`, fordelt på:

- Tørrvarer
- Krydder
- Vaskemiddel
- Matoppbevaring
- Anna

Standardavdelingar er `Arbeidslivsfag`, `Mat og helse`, `Spesped` og `Anna`.
