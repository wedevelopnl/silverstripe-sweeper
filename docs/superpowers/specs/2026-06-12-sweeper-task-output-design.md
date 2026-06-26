# Sweeper-taken: gedeelde output-laag (CLI + HTML-rapport)

Datum: 2026-06-12
Status: ontwerp goedgekeurd, ter review

## Probleem

De module heeft vier BuildTasks met drie verschillende output-mechanismen:

- `SchemaArtefactsTask` (nieuw): timestamped regels, CLI/web-bewust (`<br>` in web).
- `SweeperArtefacts` (oud, vervangen): timestamped `echo "\n"`; in de browser één
  muur tekst.
- `SweeperClearArchiveTask`: eigen `message()` met `Director::is_cli()`-check
  (web krijgt `<p>`-tags), geen timestamps, plus losse directe `echo`'s.
- `SweeperReportTask`: kale `echo "\n"`; in de browser één muur tekst.

Taken worden in de praktijk óók via de browser gedraaid (`/dev/tasks`, in
dev-modus zonder login). Juist bij destructieve taken moet de output daar goed
reviewbaar zijn: de gebruiker moet de droppable-set kunnen lezen vóór hij het
bevestigingstoken gebruikt.

## Scope

- De **drie actieve taken** krijgen de nieuwe output-laag:
  `sweeper-schema-artefacts`, `sweeper-archive`, `sweeper-report`.
- Niveau: **volledig HTML-rapport** in de browser (secties, tabellen,
  collapsibles, badges, kopieerbare actie); CLI krijgt dezelfde structuur als
  nette tekst.
- De **oude `sweeper-artefacts` wordt gedeprecieerd**, niet verfraaid: titel- en
  description-aanpassing plus een waarschuwing bij het draaien, verwijzend naar
  `sweeper-schema-artefacts`.
- README wordt bijgewerkt (nieuwe taak incl. token-flow; oude taak deprecated).

Buiten scope: wijzigingen aan de functionele logica van welke taak dan ook
(diff, token, retentie, rapportage-inhoud), en de presentatie-HTML-bestanden in
`docs/`.

## Architectuur

Nieuw pakket `src/Output/`:

- `Sweeper\Output\TaskOutput` (interface): semantische methodes; een statische
  factory `TaskOutput::create(string $title, bool $dryRun)` kiest de renderer op
  basis van `Director::is_cli()`.
- `Sweeper\Output\CliOutput`: gestructureerde platte tekst.
- `Sweeper\Output\HtmlOutput`: het HTML-rapport.

Taakcode zegt alleen wát er gemeld wordt:

```php
$out = TaskOutput::create('Sweeper: schema artefacts', dryRun: true);
$out->line('Reading current database schema');
$out->section('Droppable tables', 73);
$out->items($tableNames);
$out->section('Droppable columns', 164);
$out->table(['Tabel', 'Kolommen'], $rows);
$out->warning('REFUSED: missing or stale confirmation token.');
$out->summary(['Tabellen' => 73, 'Kolommen' => 164, 'Indexen' => 37]);
$out->action('Uitvoeren', 'run=yes token=...');
$out->finish();
```

### Semantiek per renderer

| Methode | CLI | HTML |
|---|---|---|
| create/header | titel + `(dry-run)`-prefix en timestamp per regel | titelbalk met `DRY-RUN`/`EXECUTE`-badge; timestamp alleen bij start/einde |
| `section($titel, $n)` | kopje met onderstreping | `<details open>` met teller-badge in `<summary>` (inklapbaar, geen JS) |
| `line($msg)` | gewone regel | `<p>` |
| `items($list)` | ingesprongen regels | `<ul><li>` |
| `table($headers, $rows)` | uitgelijnde kolommen | `<table>` |
| `warning($msg)` | regel met `!!`-prefix | rode kaart |
| `info($msg)` | gewone regel | neutrale kaart |
| `summary($stats)` | omkaderd tekstblok | gekleurd totalenblok |
| `action($label, $cmd)` | "Re-run with: ..." | uitgelicht codeblok met kopieer-knop (enige JS) |
| `finish()` | newline | sluit openstaande tags |

### Dragende keuzes

1. **Streaming, geen buffer.** Elke methode echo't direct; `section()` sluit
   automatisch de vorige sectie. Lang lopende taken (archive) tonen live
   voortgang. Consequentie: het samenvattingsblok staat onderaan, niet bovenaan.
2. **Self-contained HTML.** De header emit één inline `<style>`-blok en het
   mini-copyscript. Geen TaskRunner-CSS-config of host-project-aanpassing nodig.
3. **Escaping standaard.** Alle dynamische strings gaan in de HTML-renderer door
   `Convert::raw2xml()` (tabel-/kolomnamen komen uit de database).
4. **Puur en testbaar.** Beide renderers zijn input → string (echo), zonder
   DB-afhankelijkheid; unit-testbaar via output buffering.

## Integratie per taak

### sweeper-schema-artefacts

- Secties: Schema lezen (incl. telling `N reference tables recorded, M tables in
  database`), Tabellen (n), Kolommen (n op m tabellen, als tabel
  `tabel → kolommen`), Indexen (idem).
- Token-weigering → `warning()`. Vervolgcommando → `action()` met zowel het
  CLI-commando als de volledige URL-variant.
- `summary()`: tabellen/kolommen/indexen + modus.
- Functionele logica (diff, token, PRIMARY-guard, abort-on-error) ongewijzigd;
  alleen log-aanroepen vervangen.

### sweeper-archive

- Eén sectie per versioned class. Deze secties renderen **dichtgeklapt**
  (zonder `open`-attribuut): bij tientallen classes is het overzicht de teller
  per class; openklappen toont de details. De overige taken houden hun secties
  standaard open.
- Binnen de sectie: de drie operaties (drafts trimmen, archived prunen, orphans)
  als regels met aantallen; snapshot-cleanup indien aanwezig.
- `summary()`: totalen per categorie + `keep`-waarde + modus (`dry/yes/fast`).
- Alle directe `echo`'s en het eigen `message()` vervangen door de nieuwe API.

### sweeper-report

- Twee secties: DataObjects zonder instances (n), nooit toegepaste
  DataExtensions (n), beide als `items()`.
- De bestaande NOTE wordt `info()`. Actieve filters (`namespace-filter`,
  `no-silverstripe-filter`) worden in de header getoond.

### sweeper-artefacts (deprecation)

- Titel: "DEPRECATED: use sweeper-schema-artefacts". Description: uitleg
  (vereist `CREATE DATABASE`-rechten) + verwijzing naar de opvolger.
- Bij run: eerst een deprecation-`warning()` via de nieuwe API, daarna bestaand
  gedrag ongewijzigd.

## Tests

- Unit tests op beide renderers: aanroepvolgorde → verwachte output; expliciete
  escaping-test (tabelnaam met `<script>`); sectie-autoclose-test.
- Bestaande `SchemaDiffTest` blijft; geen functionele wijzigingen elders dus
  geen nieuwe functionele tests.

## Verificatie (Olympia, via de bestaande bind-mount)

1. Alle drie de actieve taken draaien in browser én CLI; output visueel
   beoordelen.
2. Schema-taak: bevestigen dat het token **ongewijzigd** blijft ten opzichte van
   vóór de refactor (presentatie mag de droppable-set niet raken) en dat de
   weigeringspaden blijven werken.
3. Oude taak: deprecation-melding zichtbaar, gedrag verder ongewijzigd.

## Open punten

- Exacte visuele stijl van het HTML-rapport (kleuren/dichtheid) wordt tijdens
  implementatie verfijnd; functioneel kader ligt vast in de tabel hierboven.
- `sweeper-archive` heeft veel log-regels in de batch-lus; bij implementatie
  bepalen welke regels `line()` blijven en welke samengevat worden tot tellers
  per sectie (geen functionele wijziging, alleen verbosity).
