# SweeperArtefactsTask: SQLite in-memory schema-vergelijking

Datum: 2026-06-02
Status: VERVANGEN, niet doorgezet

> Deze SQLite in-memory aanpak is niet doorgezet. Vervangen door
> `2026-06-02-sweeper-schema-recording-manager-design.md`, dat het schema opneemt
> via een recording schema manager (geen database, geen `silverstripe/sqlite3`
> dependency, en speciale indextypes blijven native correct). Dit document blijft
> bewaard als overwogen alternatief en beslisgeschiedenis.

## Probleem

`SweeperArtefactsTask` vergelijkt het live databaseschema met een "schoon" schema
om verweesde tabellen, kolommen en indexen op te sporen en te verwijderen. Het
schone schema wordt nu gebouwd met `SilverStripe\ORM\Connect\TempDatabase`.

`TempDatabase::build()` maakt op de geconfigureerde connectie een **echte**
database aan (`CREATE DATABASE "ss_tmpdb_<time>_<rand>"`) en dropt die weer bij
`kill()`. Dit is geen in-memory database: voor MySQL/MariaDB is het altijd een
fysieke database geweest (de code is identiek tussen SS 4.13 en SS 5.2). De
beschrijving van de taak ("Builds a clean in-memory database") is daardoor
onjuist voor MySQL-projecten.

Het concrete probleem: op moderne/managed hosting heeft de applicatie-DB-gebruiker
geen `CREATE DATABASE`/`DROP DATABASE`-rechten. `TempDatabase::build()` faalt dan
op `MySQLSchemaManager::createDatabase()`. De huidige aanpak is daardoor
onbruikbaar in die omgevingen.

## Doel

De CREATE DATABASE-afhankelijkheid wegnemen door het schone schema voortaan in
een **echte in-memory SQLite-database** te bouwen, zonder dat de taak
serverrechten nodig heeft, en zonder concessies aan het veilig kunnen opruimen
van verweesde indexen.

## Gekozen richting

- Het schone schema wordt **altijd** via SQLite in-memory gebouwd (de
  MySQL-`TempDatabase`-aanpak wordt volledig vervangen, één codepad).
- Tabellen en kolommen worden vergeleken op **naam** (engine-onafhankelijk
  identiek, want afgeleid van de DataObject-velddefinities).
- Indexen worden vergeleken op **signatuur** (`type` + gesorteerde kolommen), niet
  op naam, omdat indexnamen tussen SQLite en MySQL kunnen verschillen. Een
  verweesde index wordt gedropt op zijn echte MySQL-naam.

## Architectuur

De taak wordt verplaatst naar de correcte namespace
`Sweeper\Tasks\SweeperArtefactsTask` (was `App\Tasks\SweeperArtefacts`, een
PSR-4-afwijking; de bestandsnaam matcht al). De logica wordt opgesplitst in
afgebakende, los testbare onderdelen:

- `captureSchema(SchemaManager $schema): array` — leest van een willekeurige
  connectie het schema uit als
  `{ table => { columns: string[], indexes: array<signature, indexName> } }`.
  Gebruikt voor zowel de MySQL- als de SQLite-connectie. Bij het huidige
  (MySQL) schema is `indexName` de echte naam die nodig is voor de DROP; bij het
  schone (SQLite) schema worden alleen de signatuur-keys gebruikt en is de naam
  irrelevant.
- `indexSignature(array $indexInfo): string` — bouwt de engine-onafhankelijke
  signatuur, bijvoorbeeld `unique:SubTitle,Title`. Kolommen worden alfabetisch
  gesorteerd zodat volgorde niet meetelt.
- `diffSchemas(array $current, array $clean): array` — pure functie, geen DB.
  Levert `{ tables: string[], columns: array<table, string[]>, indexes:
  array<table, mysqlName[]> }`.
- `buildCleanSchema(): array` — registreert een aparte in-memory SQLite-connectie,
  draait `TempDatabase->build()` daarop, leest via `captureSchema`, en ruimt op
  met `kill()`.
- `expectedSpecialIndexes(): array` — leidt de signaturen van speciale
  indextypes (`fulltext`/`hash`/`rtree`) af uit
  `DataObjectSchema::databaseIndexes()`, gemapt per tabel via `getTableNames()`.
  Engine-onafhankelijk; nodig omdat SQLite deze types niet rendert.
- `dropTables/dropIndexes/dropColumns` — blijven bestaan, draaien expliciet op de
  MySQL-connectie, met `PRIMARY`-bescherming.

### Verbindingsbeheer

Een aparte benoemde connectie (`sweeper_clean`) van het type SQLite3 in-memory
wordt bij runtime geregistreerd. `new TempDatabase('sweeper_clean')` bouwt
daarop. De default MySQL-connectie blijft gedurende de hele taak actief en
geselecteerd.

Hierdoor vervalt de huidige reconnect-hack (`SweeperArtefactsTask.php:80-89`):
`kill()` raakt alleen de SQLite-connectie, de MySQL-connectie wordt nooit
losgekoppeld. SilverStripe's eigen unit-test-infrastructuur draait `TempDatabase`
standaard op SQLite in-memory, dus dit is een beproefd pad.

## Dataflow

1. Lees het huidige schema van de default (MySQL) connectie via `captureSchema`.
2. Bouw het schone schema via `buildCleanSchema` (SQLite in-memory, eigen
   connectie).
3. `diffSchemas`: tabellen/kolommen op naam, indexen op signatuur.
4. Pas DROP-statements toe op de MySQL-connectie (default: dry-run), met
   `PRIMARY`-bescherming en index-fail-safe.

## Indexvergelijking en veiligheid

De signatuur is `type + alfabetisch gesorteerde kolommen` (bijv.
`unique:SubTitle,Title`). Een MySQL-index is verweesd als zijn signatuur niet in
de schone set voorkomt; verwijderen gebeurt op de echte MySQL-naam. De schone set
wordt **gelaagd** opgebouwd, omdat SQLite niet alle indextypes kan renderen:

- **`index` / `unique`** (het overgrote deel): signaturen uit het SQLite-build
  schema. SQLite rendert deze betrouwbaar en dit dekt ook geaugmenteerde tabellen
  (`_Versions`, `_Live`, `many_many`).
- **`fulltext` / `hash` / `rtree`**: SQLite kent deze niet (zou ze overslaan of als
  gewone index renderen, met false positives tot gevolg, en mogelijk zelfs een
  build-fout). De verwachte signaturen voor deze types komen daarom uit
  `expectedSpecialIndexes()` (ORM-metadata via `databaseIndexes()`), niet uit de
  SQLite-build. Zo wordt een legitieme fulltext-index nooit onterecht gedropt, en
  een écht verweesde fulltext-index (niet in de metadata) nog steeds gevangen.

Veiligheidsmechanismen:

- `PRIMARY` wordt nooit gedropt, ongeacht de diff. Harde uitsluiting.
- Fail-safe: als de SQLite-`indexList()` voor een tabel lege of ontbrekende
  `columns` teruggeeft, kan er geen betrouwbare `index`/`unique`-signatuur gebouwd
  worden. Het droppen van die types wordt voor die tabel overgeslagen met een
  duidelijke waarschuwing. Geen stille of foutieve drops.

Onderbouwing: `MySQLSchemaManager::indexList()` levert per index `name`, `columns`
(geordende kolomlijst) en `type` (`index|unique|fulltext|hash|rtree`), en
`DataObjectSchema::databaseIndexes()` levert de gedeclareerde index-definities
inclusief `type`. De kolomnamen zijn identiek over engines heen, dus de signatuur
is betrouwbaar. De ORM-metadata is daarbij de waarheidsbron: zowel SQLite als
MySQL zijn slechts renderings van diezelfde class-definities.

## Validatiestrategie

De betrouwbaarheid hangt af van het retourformaat van de SQLite-schemamanager.
Drie lagen dekken dit af:

1. Runtime self-check: vlak na de SQLite-build verifieert de taak dat
   `indexList()` voor minstens één bekende tabel `columns` en een herkenbaar
   `type` oplevert. Zo niet, dan degradeert index-cleanup veilig (zie fail-safe)
   en wordt dit gemeld.
2. `validate`-modus (`?run=validate`): bouwt het schone schema en zet per gedeelde
   tabel de MySQL-indexsignaturen naast de SQLite-signaturen. Verschillen worden
   volledig uitgesplitst (naam, kolommen, type). Hiermee controleer je op het
   echte project of beide engines dezelfde signaturen produceren, vóór je ooit
   `run=yes` draait.
3. Unit tests: assertions op het retourformaat van de SQLite-`indexList()` voor
   een fixture-DataObject met een bekende (unique) index, plus pure-functietests
   voor `indexSignature` en `diffSchemas` zonder DB.

Transparantie als veiligheidsmechanisme: zowel de `validate`-modus als de
dry-run-output tonen per indexkandidaat de volledige details (naam + kolommen +
type), zodat een mens een echte verweesde index kan onderscheiden van een
engine-signatuurverschil voordat er iets sneuvelt.

## Run-modi

| Modus | Gedrag |
|---|---|
| (geen) / `run=` anders | dry-run: toont alle kandidaten met volledige details |
| `run=validate` | bouwt schoon schema en vergelijkt MySQL- vs SQLite-indexsignaturen per tabel |
| `run=yes` | voert DROP's uit op MySQL, met `PRIMARY`-bescherming en index-fail-safe |

## Teststrategie

- Pure-functietests (geen DB) voor `indexSignature` en `diffSchemas` met
  handgemaakte schema-arrays die extra tabellen, kolommen en index-signaturen
  bevatten.
- Integratietest die het schone schema in SQLite bouwt en het retourformaat van
  `indexList()` voor een fixture-DataObject assert (validatielaag 3).
- De module heeft nu nog geen tests; deze worden toegevoegd onder `tests/`.

## Scope

In scope:
- Herschrijven van `SweeperArtefactsTask` volgens bovenstaande architectuur.
- Verplaatsen naar namespace `Sweeper\Tasks\SweeperArtefactsTask`.
- `$description` waarheidsgetrouw maken (in-memory klopt nu) en vermelden dat de
  MySQL-verbinding ongemoeid blijft.
- `silverstripe/sqlite3` toevoegen aan `composer.json` `require`.
- Tests toevoegen.

Buiten scope:
- `SweeperClearArchiveTask` en `SweeperReportTask` blijven ongewijzigd.
- Andere opschoonregels of refactors die niet aan dit doel bijdragen.

## Open punten voor het implementatieplan

- **Crasht de SQLite-build op een `fulltext`-spec?** (hoog) De metadata-hybride
  maakt onze diff onafhankelijk van hoe SQLite speciale types rendert, maar de
  build mag er niet op stuklopen. Verifiëren dat de SQLite3-schemamanager een
  fulltext/hash/rtree-spec netjes degradeert (negeren of als gewone index) en
  niet throwt; zo niet, dan die specs vóór de build uit de class-config strippen
  of de fout afvangen.
- **Levert `databaseIndexes()` het `type` betrouwbaar mee** voor speciale types,
  en dekt het de tabellen waar fulltext-indexen in de praktijk op staan
  (class-tabellen)? Geaugmenteerde tabellen (`_Versions`/`many_many`) hebben in de
  praktijk geen speciale-type-indexen; bevestigen.
- Verifiëren van het exacte retourformaat van de SQLite-`indexList()`
  (`columns` + `type`) tegen dat van MySQL, voor `index`/`unique` (validatielaag 3).
- Bevestigen hoe de SQLite3-connectie in-memory geregistreerd wordt
  (`memory: true` versus `path: ':memory:'`) en of `TempDatabase::build()` daar
  zonder aanpassing op draait. Dit is tevens onderdeel van validatielaag 1/2.
- Exacte composer-constraint voor `silverstripe/sqlite3` pinnen die compatibel is
  met `silverstripe/framework ^5` (kandidaat `^3.0`, te verifiëren).
