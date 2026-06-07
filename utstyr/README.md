# Utstyrslån

Utlånssystem for undervisningsmateriell og felles utstyr på skulen.

## Sider

| Side | Bruk | Tilgang |
|---|---|---|
| `dashbord.html` | Oversikt, utlån, innlevering og administrasjon | Passord |
| `vare.html?utstyr=qr-kode` | QR-side for eitt utstyr | Passord |
| `etikettar.html` | Utskrift av QR-etikettar | Passord |

## Rollar

- `tilsett`: sjå aktivt utlån, låne ut og levere inn.
- `admin`: alt tilsett kan, pluss redigering av utstyr, kategoriar, plasseringar og rapport.

## Datamodell

- `unique`: eitt konkret objekt eller sett. Kan berre vere lånt ut til ein person om gongen.
- `quantity`: mengdeutstyr, til dømes 12 mikrofonar eller 20 kompass. Fleire kan låne samtidig så lenge det er ledig mengd.

Aktive utlån er synlege for innlogga tilsette med namn på lånar og forventa innlevering. Historikk er berre tilgjengeleg for admin.

## Filer

- `api/index.php` - API for dashbord, QR-side og admin
- `api/db.php` - databasekopling og hjelpefunksjonar
- `api/config.php` - standardkonfigurasjon, les `config.local.php` på server
- `api/setup.php` - opprett/byt passord etter databaseoppsett
- `api/health.php` - sjekk databasekopling
- `sql/schema.sql` - tabellar og startkategoriar/plasseringar

## Personvern

- Systemet skal ikkje brukast til elevdata.
- Første versjon lagrar berre namn på tilsett/lånar som fri tekst, utlånt utstyr, mengd, frist og merknad.
- Namn er berre synlege etter felles tilsett-innlogging.
- Ikkje legg inn e-post, telefonnummer, fødselsdato eller sensitive opplysningar i merknadsfelt.

## Viktig etter oppsett

- Slett `api/setup.php` frå serveren når passorda er sett.
- Slett `api/health.php` frå serveren når databasekoplinga er testa.
- Lagre aldri databasepassord i git. Bruk `api/config.local.php` på serveren.
