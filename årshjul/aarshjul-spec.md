# Tverrfagleg Årshjul for ungdomsskule — Spesifikasjon for Claude Code

## 0. Rolle og arbeidsmåte

Du er ein senior fullstack-utviklar. Du byggjer ein webapp som norske ungdomsskulelærarar bruker til å planleggje skuleåret og kople saman emne på tvers av fag.

**Arbeidsreglar:**
- Bygg **fase for fase**. Stopp etter kvar fase og vis resultatet før du går vidare.
- Ta sjølvstendige avgjerder på detaljar (variabelnamn, intern struktur, mindre UI-val). **Spør berre** når noko kan endre datamodellen, brukaropplevinga merkbart, eller når krava er genuint tvetydige.
- Skriv **kommentarar på norsk (nynorsk)** i koden når forretningslogikk treng forklaring. Kode, variabelnamn og typar er på engelsk.
- Brukargrensesnittet (all synleg tekst) skal vere på **nynorsk**.
- Køyr `npm run build` og `npm run lint` før du melder ein fase som ferdig. Alt skal kompilere utan feil.

---

## 1. Teknologival

| Lag | Val | Merknad |
|---|---|---|
| Byggjeverktøy | Vite | `npm create vite@latest -- --template react-ts` |
| Språk | TypeScript | `strict: true` i `tsconfig.json` |
| UI | React 18+ | Funksjonelle komponentar + hooks |
| Styling | Tailwind CSS | Med `@tailwindcss/forms` |
| Ikon | `lucide-react` | |
| State | Zustand | Enklare enn Context for denne storleiken |
| Lagring | `localStorage` via eit `storage`-abstraksjonslag | Skal kunne bytast mot Supabase seinare utan at komponentane merkar det |
| Dra-og-slepp | `@dnd-kit/core` | Først i fase 5 |
| Dato/veke | `date-fns` | For ISO-veker |
| Testing | Vitest + React Testing Library | Minimum smoke-testar per fase |

---

## 2. Mappestruktur

```
src/
  components/
    timeline/        # Gantt-rutenett og emne-blokker
    modals/          # Dialogar for redigering
    projects/        # Tverrfagleg prosjekt-UI
    ui/              # Gjenbrukbare byggeklossar (Button, Badge, etc.)
  store/
    useAppStore.ts   # Zustand-store
    storage.ts       # Abstraksjon over localStorage
  types/
    index.ts         # Alle TypeScript-typar
  data/
    schoolYear.ts    # Vekenummer, månader, ferier
    mockData.ts      # Testdata for fase 1
    coreThemes.ts    # LK20-konstantar
  lib/
    weekUtils.ts     # Veke-utrekningar
  App.tsx
  main.tsx
```

---

## 3. Datamodell

```ts
// types/index.ts

export type CoreTheme =
  | 'folkehelse-og-livsmeistring'
  | 'demokrati-og-medborgarskap'
  | 'berekraftig-utvikling';

export interface Week {
  number: number;        // ISO-vekenummer, 1–53
  month: string;         // Nynorsk månadsnamn
  year: number;          // Kalenderår veka høyrer til
  isHoliday?: boolean;   // Ferie/fridag
  label?: string;        // "Haustferie", "Juleferie", etc.
}

export interface Subject {
  id: string;            // uuid
  name: string;
  shortName: string;     // Eks. "Nat", "Mat" — for kompakt visning
  color: SubjectColor;   // Sjå ColorToken nedanfor
  order: number;         // Rekkefølgje i tidslinja
}

export interface Topic {
  id: string;
  subjectId: string;
  title: string;
  description?: string;
  startWeek: number;     // ISO-vekenummer
  endWeek: number;       // Inkluderande
  year: number;          // Skuleåret startar her (eks. 2025 for 2025/26)
  competenceAims: string[];
  projectId?: string;    // Null = ikkje kopla til tverrfagleg prosjekt
}

export interface InterdisciplinaryProject {
  id: string;
  title: string;
  coreTheme: CoreTheme | 'annet';
  description: string;
  color: ProjectColor;
}

export interface AppState {
  schoolYearStart: number;   // Eks. 2025 = skuleåret 2025/26
  subjects: Subject[];
  topics: Topic[];
  projects: InterdisciplinaryProject[];
}
```

**Fargesystem:** Definer ein fast palett som Tailwind-klassenamn i `types/index.ts`:

```ts
export type SubjectColor = 'blue' | 'emerald' | 'amber' | 'rose' | 'violet' | 'cyan' | 'orange' | 'lime';
export type ProjectColor = 'indigo' | 'pink' | 'teal' | 'yellow';
```

Lag ein hjelpefunksjon `getColorClasses(color)` som returnerer `{ bg, border, text, ring }`-klassar. Dette unngår dynamiske Tailwind-klassar (som blir stripa bort av purge).

---

## 4. Skuleåret

Skuleåret går frå **veke 33** (medio august) til **veke 25** (ca. 20. juni), med desse feriene flagga som `isHoliday`:

- Haustferie (veke 41)
- Juleferie (veke 52 og 1)
- Vinterferie (veke 8 eller 9 — konfigurerbart)
- Påskeferie (varierer etter år, bruk `date-fns` til å rekne ut)

Lag `schoolYear.ts` med ein funksjon `generateSchoolYear(startYear: number): Week[]` som produserer full vekeliste med feriar flagga. Vinterferien er veke 9 som standard (Vestland/Bergen).

---

## 5. Kjernefunksjonalitet

### 5.1 Gantt-visning (hovudvisning)
- **Rader:** fag (Subject), sortert etter `order`.
- **Kolonnar:** veker, grupperte under månadsoverskrifter.
- **Blokker:** Topic rendrast som fargelagde blokker som spenner over `startWeek` til `endWeek`.
- Feriveker får diagonal skravering eller lys grå bakgrunn.
- Klikk på tom celle → opnar "Nytt emne"-modal med førehandsutfylt fag og startveke.
- Klikk på eksisterande blokk → opnar "Rediger emne"-modal.
- Horisontal scrolling for heile skuleåret. Fag-kolonna til venstre skal vere sticky.

### 5.2 Tverrfaglege koplingar (hovudverdien)
- Eige panel "Tverrfaglege prosjekt" (sidebar eller eigen rute).
- Opprett prosjekt → vel kjerneelement (`CoreTheme`), tittel, farge.
- Kople emne til prosjekt: frå Topic-modalen, vel frå nedtrekksliste over eksisterande prosjekt.
- **Visuell kopling i tidslinja:** alle Topic-blokker som høyrer til same prosjekt får:
  - Tjukk ramme i prosjektet sin farge
  - Eit lite ikon (`Link2` frå lucide) øvst til høgre
  - Når brukaren held musa over ein kopla blokk, blir alle andre blokker i same prosjekt utheva

### 5.3 CRUD
- Legg til / rediger / slett Topic, Subject og InterdisciplinaryProject.
- Stadfest sletting med dialog.
- Alle endringar persisterer umiddelbart til localStorage.

### 5.4 Import/eksport
- Knapp for å eksportere heile `AppState` som JSON-fil.
- Knapp for å importere JSON (med validering).
- Dette er kritisk før vi har backend — læraren må kunne ta vare på planen.

---

## 6. Tilgjengelegheit og kvalitet

- All interaktiv UI skal vere tastaturnavigerbar.
- Fargar brukt til å formidle tilhøyrsle må supplerast med tekst eller ikon (ikkje berre farge).
- Kontrast skal oppfylle WCAG AA.
- Responsivt: fungerer på laptop (primær) og tablet i landskap. Mobil er ikkje eit krav.

---

## 7. Fasar og akseptansekriterium

Etter kvar fase: køyr `npm run build`, syn eit skjermbilete eller beskriv resultatet, og vent på godkjenning.

### Fase 1: Oppsett og datagrunnlag
- Vite + React + TS + Tailwind oppe og køyrer.
- Mappestruktur som spesifisert.
- Alle typar i `types/index.ts`.
- `generateSchoolYear(2025)` returnerer korrekt veke-array.
- Mock-data: 4 fag (Norsk, Matematikk, Naturfag, Samfunnsfag), 6 emne fordelt over haustsemesteret, 1 tverrfagleg prosjekt.
- **Akseptanse:** `npm run dev` viser ein enkel side som listar mock-data som JSON for verifisering.

### Fase 2: Gantt-rutenettet
- Rutenett med fag som rader, veker som kolonnar (grupperte under månader).
- Topic-blokker rendra med riktig fargekoding og posisjon.
- Sticky fag-kolonne til venstre.
- Feriveker visuelt markerte.
- **Akseptanse:** Mock-emna syner seg på rett plass. Ingen interaksjon enno.

### Fase 3: CRUD + lagring
- Zustand-store med handlingar: `addTopic`, `updateTopic`, `deleteTopic`, `addSubject`, etc.
- `storage.ts`-abstraksjon som wrapper localStorage (med `load`, `save`, `clear`).
- Modal for oppretting/redigering av emne.
- Stadfesting ved sletting.
- Import/eksport JSON.
- **Akseptanse:** Alle endringar overlever refresh. JSON-eksport produserer gyldig fil som kan importerast tilbake.

### Fase 4: Tverrfagleg kopling
- UI for å opprette/redigere/slette `InterdisciplinaryProject`.
- Kopling av Topic til Project via modal.
- Visuell markering i tidslinja (ramme + ikon).
- Hover-uthevingseffekt.
- Panel/rute som listar alle prosjekt med kopla emne.
- **Akseptanse:** Brukaren kan lage prosjektet "Berekraftig utvikling", kople eit Naturfag- og eit Samfunnsfag-emne til det, og sjå koplinga visuelt i tidslinja.

### Fase 5 (valfri, etter godkjenning): Dra-og-slepp
- `@dnd-kit` integrert.
- Dra Topic-blokker mellom fag eller veker.
- Dra i kantane for å justere lengd.

---

## 8. Kva du ikkje skal gjere no

- Ikkje byggje backend, autentisering eller fleirbrukarstøtte.
- Ikkje legg til analytics eller tracking.
- Ikkje integrer mot LMS (Itslearning) i denne omgangen.
- Ikkje lag PDF-eksport enno.

---

**Start med fase 1. Stopp når du er ferdig og vent på klarsignal.**
