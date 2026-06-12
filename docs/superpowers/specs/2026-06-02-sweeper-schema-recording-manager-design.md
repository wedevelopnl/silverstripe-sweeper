# SchemaArtefactsTask: schema opnemen via een recording schema manager

Datum: 2026-06-02 (verificatie en token: 2026-06-12)
Status: geïmplementeerd en geverifieerd op host-project (dry-run + A/B);
`run=yes` happy path bewust niet uitgevoerd

Vervangt: `2026-06-02-sweeper-artefacts-sqlite-schema-design.md` (de SQLite
in-memory aanpak is niet doorgezet, zie "Afweging" hieronder).

## Probleem

`SweeperArtefactsTask` vergelijkt het live schema met een "schoon" schema om
verweesde tabellen, kolommen en indexen op te sporen en te verwijderen. Het schone
schema wordt gebouwd met `TempDatabase`, dat een **echte** database aanmaakt
(`CREATE DATABASE "ss_tmpdb_..."`). Op moderne/managed hosting heeft de
applicatie-DB-gebruiker geen `CREATE DATABASE`-recht, waardoor de taak stukloopt.

## Afweging

Eerst onderzocht: het schone schema in een echte in-memory SQLite bouwen (zie de
vervangen spec). Dat werkte, maar vereiste een extra dependency
(`silverstripe/sqlite3`) en een metadata-hybride om speciale indextypes
(`fulltext`/`hash`/`rtree`) op te vangen die SQLite niet rendert.

Kerninzicht dat tot de huidige aanpak leidde: `dev/build` en `TempDatabase`
bouwen het schema allebei via `TableBuilder::buildTables()`, dat
`DataObject::requireTable()` plus elke extensie's `augmentDatabase()` draait. Die
calls bufferen de gewenste tabellen/kolommen/indexen en flushen ze aan het eind van
`DBSchemaManager::schemaUpdate()` via `createTable()`. De database eronder is enkel
het rendertarget.

Door een **opnemende schema manager** als actieve manager te installeren en
`createTable()` te onderscheppen, vangen we het volledige referentieschema zonder
enige database: geen `CREATE DATABASE`, geen temp-database, geen extra dependency.
En speciale indextypes blijven correct, want de opname zit vóór de render-stap.

## Gekozen aanpak

Een **aparte** BuildTask naast de bestaande `sweeper-artefacts` (die blijft
volledig ongewijzigd). Zo blijft de huidige situatie intact en fungeert de nieuwe
taak meteen als geïsoleerde proef.

## Componenten

- `Sweeper\Schema\RecordingSchemaManager` (extent `MySQLSchemaManager`):
  onderschept `createTable()` en registreert kolomnamen + index-specs in plaats van
  SQL uit te voeren. `tableList()`/`fieldList()`/`indexList()`/`hasTable()`
  rapporteren een lege database, zodat elke vereiste als "create" behandeld en dus
  opgenomen wordt. Dit is essentieel: zou de manager de echte DB rapporteren, dan
  zou een bestaande kolom/index niet gebufferd worden en daarna onterecht als
  verweesd gelden.
- `Sweeper\Schema\SchemaDiff`: pure diff (geen DB, los testbaar). Tabellen en
  kolommen op naam, indexen op signatuur (`type` + gesorteerde kolommen). `PRIMARY`
  wordt nooit gedropt.
- `Sweeper\Tasks\SchemaArtefactsTask` (segment `sweeper-schema-artefacts`):
  orkestreert de opname en de diff.

## Mechaniek

Geverifieerd in framework 5.2:
- `TableBuilder::buildTables()` draait `$schema->schemaUpdate(...)` en daarbinnen
  `$singleton->requireTable()` per class.
- `schemaUpdate()` buffert en flusht aan het eind via `createTable()`/`alterTable()`.
- `requireTable()` voegt de impliciete `ID`-kolom toe en buffert velden/indexen.
- `DB::require_table()` roept `requireTable()` **direct** aan (geen geneste
  `schemaUpdate`), dus `augmentDatabase()` (Versioned `_Versions`/`_Live`,
  `many_many`) buffert mee in dezelfde outer `schemaUpdate` en wordt opgenomen.
- `Database::setSchemaManager()` is publiek en koppelt de manager terug aan de
  connectie (`setDatabase()`).

Flow in `SchemaArtefactsTask`:

1. Lees het huidige schema van de actieve (echte) manager.
2. `setSchemaManager(recorder)` in een `try`, draai de `requireTable()`-traversal,
   en zet in `finally` altijd de echte manager terug.
3. Normaliseer de opname en diff tegen het huidige schema.
4. Pas DROP's toe op de echte connectie (default: dry-run).

## Indexen

Signatuur = `type` + alfabetisch gesorteerde kolommen. Een MySQL-index waarvan de
signatuur niet in het opgenomen schema voorkomt, is verweesd en wordt op zijn echte
MySQL-naam gedropt. Omdat de opname vóór de render zit, behouden `fulltext`/`hash`/
`rtree` hun type en matchen ze correct: de eerder bedachte metadata-hybride is
hierdoor overbodig.

## Veiligheid

- Default dry-run; `run=yes` voert pas DROP's uit.
- Bevestigingstoken: de dry-run print een token (hash over de gecanonicaliseerde
  droppable-set, `SchemaDiff::confirmationToken()`). `run=yes` weigert zonder
  `token=<waarde>` of met een verouderd token. Dit dwingt een voorafgaande review
  af én garandeert dat de uitgevoerde set exact de gereviewde set is: wijzigt het
  schema tussen review en run (deploy, dev/build, handmatige wijziging), dan
  matcht het token niet meer. Stateless, werkt identiek via CLI en browser.
- `PRIMARY` wordt nooit gedropt (in de diff én als extra guard in de drop).
- Abort-on-error: faalt de opname, dan stopt de taak zonder wijzigingen, want een
  onvolledig referentieschema is onveilig om te diffen.
- Tabelmatching is hoofdletter-ongevoelig. MySQL met `lower_case_table_names=1`
  geeft tabelnamen in kleine letters terug terwijl de opname de class-case
  gebruikt; hoofdlettergevoelig vergelijken vlagde in de praktijk élke tabel als
  droppable (gevonden bij verificatie op Olympia, zie hieronder; afgedekt met een
  regressietest).
- Zelf-validerend: op een net ge-`dev/build`-te database hoort de dry-run een lege
  droppable-lijst te geven (referentie == gebouwd schema). Een niet-lege lijst op
  een schone DB duidt op een opname-gat, dus een bug.

Toegangscontrole is framework-gating (`/dev/tasks`: in dev-modus open, op
test/live `ADMIN`/`ALL_DEV_ADMIN`/`BUILDTASK_CAN_RUN`); de taak voegt daar zelf
geen permissiecheck aan toe. Het token is geen geheim maar een
review-bindingschecksum.

## Scope

In scope:
- Nieuwe bestanden: `src/Schema/RecordingSchemaManager.php`,
  `src/Schema/SchemaDiff.php`, `src/Tasks/SchemaArtefactsTask.php`.
- Tests: `tests/Schema/SchemaDiffTest.php` + `autoload-dev` in `composer.json`.

Buiten scope:
- De bestaande `App\Tasks\SweeperArtefacts` blijft ongewijzigd.
- `SweeperClearArchiveTask` en `SweeperReportTask` blijven ongewijzigd.
- Geen nieuwe runtime-dependency (geen `silverstripe/sqlite3`).
- MySQL/MariaDB only (recorder extent `MySQLSchemaManager`), consistent met de rest
  van de module.

## Verificatie: uitgevoerd op Olympia (SS 5.2.22, Docker, MySQL 8.0)

Lokale module via bind-mount over `vendor/wedevelopnl/silverstripe-sweeper`
gemount (`compose.sweeper.yml`, expliciete `-f`-keten naast de bestaande
`compose.override.yaml`).

Resultaten:

- Eerste dry-run vlagde **alle 384 tabellen** als droppable: de case-bug (zie
  Veiligheid). Na de fix: geen enkele kern-tabel meer gevlagd.
- Diagnostische telling: 311 referentie-tabellen opgenomen, 384 in de database;
  bewijst dat de opname vult (de fout zat in de vergelijking, niet de opname).
- A/B-kruiscontrole tegen de oude TempDatabase-taak op dezelfde database:
  droppable **tabellen 73 vs 73 identiek**, droppable **kolomregels 37 vs 37
  letterlijk identiek** (incl. `member.TwitterAccountName`, met grep bevestigd
  als 0 code-referenties). Indexen wijken bewust af (signatuur i.p.v. naam) en
  oogden coherent.
- Index-spec-vorm (`['type'=>, 'columns'=>]`) bevestigd via de dry-run.
- Token-mechanisme getest: dry-run print het token; `run=yes` zonder token en met
  fout token worden beide geweigerd; tabel-telling onveranderd (384).

## Open punten

- Het happy path van `run=yes` (met geldig token) is niet uitgevoerd
  (destructief); alleen op een wegwerp-database testen.
- Andere framework-versies dan 5.2 niet getest.
