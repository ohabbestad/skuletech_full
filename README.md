# SkuleTech

SkuleTech er ei sandkasse for å utforske korleis digitale verktøy kan utviklast og brukast i skulen.
Prosjektet inneheld opne læringsressursar, undervisningsopplegg og praktiske verktøy som kan brukast direkte i nettlesaren.

Eit viktig prinsipp er at verktøya skal vere enkle å ta i bruk, fungere i skulekvardagen og tilfredsstille krava til personvern og GDPR.
Der det er mogleg, skal ressursane kunne brukast utan innlogging, sporing eller innsamling av personopplysningar.

## Innhald

Prosjektet inneheld fleire undervisningsressursar og verktøy:

- **Koding i matematikk**
  Python-ressursar for ungdomstrinnet, med modular om variablar, datatypar, input, rekneartar, boolean og løkker.
  Inneheld òg kodevindauge, turtle-grafikk og oppgåvebank.

- **Arbeidslivsfag**
  Ressursar for HMS, hygiene og kantinedrift.
  Inneheld presentasjonar, testar med diplom og verktøy for drift av skulekantine.

- **Valfag trafikk**
  Modular knytte til trafikkopplæring, mellom anna førstehjelp, synlegheit, etikk, nærmiljø og framtidig mobilitet.

- **Naturfag**
  Interaktive naturfagressursar under utvikling.

- **Verktøy**
  Praktiske lærarverktøy som gruppemaskin og klassekart.

## Prinsipp

SkuleTech er bygd rundt nokre enkle ideal:

- Verktøya skal vere nyttige i faktisk undervisning.
- Ressursane skal vere opne og gratis å bruke.
- Elevane skal i størst mogleg grad kunne bruke verktøya utan konto.
- Løysingane skal samle inn minst mogleg data.
- Personvern og GDPR skal vere med frå starten, ikkje leggjast på til slutt.
- Verktøya skal fungere på vanlege einingar med nettlesar.

## Teknologi

Dette er hovudsakleg ein statisk nettstad bygd med:

- HTML
- CSS
- JavaScript
- Tailwind CSS

Tailwind blir bygd frå `css/input.css` til `css/tailwind.css`.

## Kom i gang

Installer avhengigheiter:

```bash
npm install
```

Bygg CSS:

```bash
npm run build
```

Køyr Tailwind i watch-modus under utvikling:

```bash
npm run watch
```

Opne deretter `index.html` i nettlesaren.

## Struktur

```txt
.
├── index.html              # Hovudside for SkuleTech
├── css/                    # Tailwind input og bygd CSS
├── js/                     # Felles JavaScript
├── bilete/                 # Felles bilete og logoar
├── koding/                 # Koding i matematikk
├── ALF/                    # Arbeidslivsfag
├── trafikk/                # Valfag trafikk
├── naturfag/               # Naturfagressursar
├── verktøy/                # Lærarverktøy
└── årshjul/                # Plan/skisse for årshjul
```

## Publisering

Prosjektet har ein GitHub Actions-workflow som byggjer Tailwind CSS og publiserer nettstaden via FTP til Domeneshop ved push til `main`.

Workflowen ligg i:

```txt
.github/workflows/deploy.yml
```

## Mål

Målet med SkuleTech er å utforske, byggje og dele digitale verktøy som gjer undervisning enklare, meir praktisk og meir tilgjengeleg.

Dette prosjektet er både ein stad for ferdige ressursar og ein stad for utprøving.
Nokre delar er klare til bruk, medan andre er under utvikling eller fungerer som prototypar.
