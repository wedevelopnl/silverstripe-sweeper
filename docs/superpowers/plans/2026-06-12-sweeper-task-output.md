# Sweeper Task Output Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eén gedeelde output-laag (CLI-tekst + HTML-rapport) voor de drie actieve sweeper-taken, plus deprecation van de oude `sweeper-artefacts`.

**Architecture:** Abstract `Sweeper\Output\TaskOutput` met een statische factory die op `Director::is_cli()` kiest tussen `CliOutput` (gestructureerde tekst) en `HtmlOutput` (self-contained HTML-rapport met `<details>`-secties). Taken roepen uitsluitend semantische methodes aan; renderers zijn puur (input → echo) en unit-testbaar via output buffering. Streaming: elke methode echo't direct, samenvatting onderaan.

**Tech Stack:** PHP 8 / SilverStripe 5 vendormodule. Tests draaien via het `composer:2` Docker-image (de host heeft geen PHP). Live verificatie via de bestaande bind-mount in het Olympia-project.

**Spec:** `docs/superpowers/specs/2026-06-12-sweeper-task-output-design.md`

## Belangrijke context voor de uitvoerder

- **Geen PHP op de host.** Alles wat PHP draait gaat via Docker. Unit tests in de module-map:
  ```bash
  cd "/Users/jeroen/Development/klanten/WeDevelop/modules/silverstripe/silverstripe-sweeper"
  docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install --no-interaction --quiet && vendor/bin/phpunit"
  ```
- **Geneste `vendor/` in de module is veilig** voor het Olympia-project waar deze module ge-bind-mount is: `ManifestFileFinder` van het framework slaat geneste vendor-mappen expliciet over (geverifieerd in de broncode, regel "Skip nested vendor folders").
- **Olympia-verificatie**: de module is gemount in Olympia (`compose.sweeper.yml`). Commando's vanuit `/Users/jeroen/Development/klanten/Olympia/website`, altijd met de volledige `-f`-keten:
  ```bash
  docker compose -f compose.yml -f compose.override.yaml -f compose.sweeper.yml exec -T php ./vendor/bin/sake dev/tasks/<segment>
  ```
  Web: `https://localhost:17080/dev/tasks/<segment>` (curl met `-sk`).
- **Harde eis bij Task 4**: het bevestigingstoken van `sweeper-schema-artefacts` moet vóór en na de refactor identiek zijn (presentatie mag de droppable-set niet raken). Huidige token op de Olympia-DB: `4245703986` (alleen geldig zolang die DB niet wijzigt; vergelijk dus vers-voor met vers-na).
- **Functionele logica nooit wijzigen** (diff, token, PRIMARY-guard, retentie-queries). Alleen presentatie.

## File Structure

- Create: `src/Output/TaskOutput.php` — abstract base + factory (`create()`), houdt `$dryRun`
- Create: `src/Output/CliOutput.php` — tekstrenderer (timestamps, secties met underline, tekstkader-summary)
- Create: `src/Output/HtmlOutput.php` — HTML-renderer (inline `<style>`, `<details>`, tabellen, copy-knop)
- Create: `tests/Output/CliOutputTest.php`
- Create: `tests/Output/HtmlOutputTest.php`
- Create: `phpunit.xml` — bootstrap zodat tests standalone draaien
- Modify: `src/Tasks/SchemaArtefactsTask.php` — log() vervangen door TaskOutput
- Modify: `src/Tasks/SweeperReportTask.php` — echo's vervangen
- Modify: `src/Tasks/SweeperClearArchiveTask.php` — message() vervangen, secties per class, summary met totalen
- Modify: `src/Tasks/SweeperArtefactsTask.php` — alleen deprecation (titel, description, warning bij run)
- Modify: `README.md` — nieuwe taak + token-flow documenteren, oude deprecaten
- Modify: `CLAUDE.md` — commands/architectuur actualiseren

### API-contract (bindend voor alle taken)

```php
TaskOutput::create(string $title, ?bool $dryRun): self
// $dryRun: true = DRY-RUN-badge/prefix, false = EXECUTE-badge, null = geen badge (read-only report)

$out->line(string $message): void                    // voortgangsregel
$out->info(string $message): void                    // neutrale kaart / gewone regel
$out->warning(string $message): void                 // rode kaart / "!! "-regel
$out->section(string $title, ?int $count = null, bool $open = true): void
                                                     // sluit automatisch de vorige sectie
$out->items(array $items): void                      // lijst binnen sectie
$out->table(array $headers, array $rows): void       // tabel binnen sectie
$out->summary(array $stats): void                    // label => waarde; sluit sectie eerst
$out->action(string $label, string $command): void   // uitgelicht commando (HTML: copy-knop)
$out->finish(): void                                 // sluit alles af
```

---

### Task 0: Feature-branch en baseline-commit

Er staat ouder, ongecommit werk in de working tree (de hele schema-artefacts feature). Dat gaat eerst als baseline op een branch, zodat de output-laag-commits schoon te reviewen zijn.

**Files:** geen nieuwe; git-administratie.

- [ ] **Step 1: Branch aanmaken**

```bash
cd "/Users/jeroen/Development/klanten/WeDevelop/modules/silverstripe/silverstripe-sweeper"
git checkout -b feature/task-output
```

- [ ] **Step 2: Baseline committen (bestaand werk: schema-artefacts + docs + tests)**

```bash
git add CLAUDE.md composer.json docs/ src/Schema/ src/Tasks/SchemaArtefactsTask.php tests/
git commit -m "feat: add sweeper-schema-artefacts task (recording schema manager, confirmation token)"
```

- [ ] **Step 3: Verifieer schone status**

Run: `git status --short`
Expected: lege output (alles gecommit).

---

### Task 1: PHPUnit standalone werkend krijgen

De module heeft tests (`SchemaDiffTest`) die nog nooit gedraaid zijn: er is geen `phpunit.xml` en geen vendor. Eerst de testrunner werkend, dan pas TDD op de renderers.

**Files:**
- Create: `phpunit.xml`

- [ ] **Step 1: Schrijf `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheResultFile=".phpunit.result.cache">
    <testsuites>
        <testsuite name="sweeper">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 2: Draai de bestaande tests**

```bash
cd "/Users/jeroen/Development/klanten/WeDevelop/modules/silverstripe/silverstripe-sweeper"
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install --no-interaction --quiet && vendor/bin/phpunit"
```

Expected: `OK (9 tests, ...)` — alle bestaande `SchemaDiffTest`-tests slagen. Faalt `composer install` op platform-eisen, voeg `--ignore-platform-reqs` toe aan het composer-commando.

- [ ] **Step 3: Verifieer dat Olympia's dev/build niet stoort op de geneste vendor**

```bash
cd "/Users/jeroen/Development/klanten/Olympia/website"
docker compose -f compose.yml -f compose.override.yaml -f compose.sweeper.yml exec -T php ./vendor/bin/sake dev/build flush=1 2>&1 | tail -3
```

Expected: normale build-output, geen duplicate-class-fouten. (Mocht het tóch fout gaan: `touch vendor/_manifest_exclude` binnen de module en flush opnieuw.)

- [ ] **Step 4: Zorg dat vendor/ niet meegecommit wordt en commit**

Check `.gitignore`; bevat die nog geen `vendor/`, voeg toe. Daarna:

```bash
cd "/Users/jeroen/Development/klanten/WeDevelop/modules/silverstripe/silverstripe-sweeper"
git add phpunit.xml .gitignore
git commit -m "chore: standalone phpunit setup"
```

---

### Task 2: TaskOutput base + CliOutput (TDD)

**Files:**
- Create: `src/Output/TaskOutput.php`
- Create: `src/Output/CliOutput.php`
- Test: `tests/Output/CliOutputTest.php`

- [ ] **Step 1: Schrijf de falende test**

```php
<?php

namespace Sweeper\Tests\Output;

use PHPUnit\Framework\TestCase;
use Sweeper\Output\CliOutput;

class CliOutputTest extends TestCase
{
    private function render(callable $calls, ?bool $dryRun = true): string
    {
        ob_start();
        $out = new CliOutput('Test task', $dryRun);
        $calls($out);
        return ob_get_clean();
    }

    public function testHeaderShowsTitleAndMode(): void
    {
        $text = $this->render(fn ($out) => $out->finish());

        $this->assertStringContainsString('Test task', $text);
        $this->assertStringContainsString('Mode: DRY-RUN', $text);
    }

    public function testNullDryRunOmitsModeAndPrefix(): void
    {
        $text = $this->render(fn ($out) => $out->line('hello'), null);

        $this->assertStringNotContainsString('Mode:', $text);
        $this->assertStringNotContainsString('(dry-run)', $text);
        $this->assertStringContainsString('hello', $text);
    }

    public function testLineIsTimestampedAndPrefixed(): void
    {
        $text = $this->render(fn ($out) => $out->line('working'));

        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T.*: \(dry-run\) working/', $text);
    }

    public function testWarningHasMarker(): void
    {
        $text = $this->render(fn ($out) => $out->warning('danger'));

        $this->assertStringContainsString('!! danger', $text);
    }

    public function testSectionItemsAndTable(): void
    {
        $text = $this->render(function ($out) {
            $out->section('Droppable tables', 2);
            $out->items(['old_a', 'old_b']);
            $out->section('Droppable columns', 1);
            $out->table(['Table', 'Columns'], [['page', 'Foo, Bar']]);
        });

        $this->assertStringContainsString("Droppable tables (2)\n--------------------", $text);
        $this->assertStringContainsString('  - old_a', $text);
        $this->assertStringContainsString('Table', $text);
        $this->assertStringContainsString('page', $text);
    }

    public function testSummaryIsFramed(): void
    {
        $text = $this->render(fn ($out) => $out->summary(['Tables' => 73, 'Columns' => 164]));

        $this->assertStringContainsString('| Tables: 73', $text);
        $this->assertStringContainsString('+--', $text);
    }

    public function testActionShowsCommand(): void
    {
        $text = $this->render(fn ($out) => $out->action('CLI', 'run=yes token=abc'));

        $this->assertStringContainsString('> CLI: run=yes token=abc', $text);
    }
}
```

- [ ] **Step 2: Draai de test, verwacht FAIL**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install --no-interaction --quiet && vendor/bin/phpunit --filter CliOutputTest"`
Expected: Error "Class Sweeper\Output\CliOutput not found".

- [ ] **Step 3: Schrijf `src/Output/TaskOutput.php`**

```php
<?php

namespace Sweeper\Output;

use SilverStripe\Control\Director;

/**
 * Semantic task output. Tasks state WHAT to report; the renderer decides HOW.
 *
 * Streaming by design: every method echoes immediately, so long-running tasks
 * show live progress. Consequence: summary() comes at the end, never on top.
 *
 * $dryRun semantics: true shows a DRY-RUN marker, false shows EXECUTE,
 * null shows neither (read-only tasks like sweeper-report).
 */
abstract class TaskOutput
{
    protected ?bool $dryRun;

    public static function create(string $title, ?bool $dryRun): self
    {
        return Director::is_cli()
            ? new CliOutput($title, $dryRun)
            : new HtmlOutput($title, $dryRun);
    }

    public function __construct(string $title, ?bool $dryRun)
    {
        $this->dryRun = $dryRun;
        $this->header($title);
    }

    abstract protected function header(string $title): void;

    abstract public function line(string $message): void;

    abstract public function info(string $message): void;

    abstract public function warning(string $message): void;

    /**
     * Starts a section; automatically closes the previous one.
     * $open only affects the HTML renderer (collapsed vs expanded).
     */
    abstract public function section(string $title, ?int $count = null, bool $open = true): void;

    /** @param string[] $items */
    abstract public function items(array $items): void;

    /**
     * @param string[] $headers
     * @param array<array<string|int>> $rows
     */
    abstract public function table(array $headers, array $rows): void;

    /** @param array<string, string|int> $stats */
    abstract public function summary(array $stats): void;

    abstract public function action(string $label, string $command): void;

    abstract public function finish(): void;
}
```

- [ ] **Step 4: Schrijf `src/Output/CliOutput.php`**

```php
<?php

namespace Sweeper\Output;

class CliOutput extends TaskOutput
{
    protected function header(string $title): void
    {
        echo "\n{$title}\n" . str_repeat('=', mb_strlen($title)) . "\n";

        if ($this->dryRun !== null) {
            echo 'Mode: ' . ($this->dryRun ? 'DRY-RUN' : 'EXECUTE') . "\n";
        }
    }

    public function line(string $message): void
    {
        echo $this->stamp() . $message . "\n";
    }

    public function info(string $message): void
    {
        $this->line($message);
    }

    public function warning(string $message): void
    {
        echo $this->stamp() . '!! ' . $message . "\n";
    }

    public function section(string $title, ?int $count = null, bool $open = true): void
    {
        $label = $title . ($count !== null ? " ({$count})" : '');
        echo "\n{$label}\n" . str_repeat('-', mb_strlen($label)) . "\n";
    }

    public function items(array $items): void
    {
        foreach ($items as $item) {
            echo "  - {$item}\n";
        }
    }

    public function table(array $headers, array $rows): void
    {
        $widths = [];
        foreach (array_merge([$headers], $rows) as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string)$cell));
            }
        }

        $render = function (array $row) use ($widths): string {
            $cells = [];
            foreach (array_values($row) as $i => $cell) {
                $cells[] = str_pad((string)$cell, $widths[$i]);
            }
            return '  ' . rtrim(implode('  ', $cells));
        };

        echo $render($headers) . "\n";
        foreach ($rows as $row) {
            echo $render($row) . "\n";
        }
    }

    public function summary(array $stats): void
    {
        $lines = [];
        foreach ($stats as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }
        $inner = max(array_map('mb_strlen', $lines));

        echo "\n+" . str_repeat('-', $inner + 2) . "+\n";
        foreach ($lines as $line) {
            echo '| ' . str_pad($line, $inner) . " |\n";
        }
        echo '+' . str_repeat('-', $inner + 2) . "+\n";
    }

    public function action(string $label, string $command): void
    {
        echo "\n> {$label}: {$command}\n";
    }

    public function finish(): void
    {
        echo "\n";
    }

    private function stamp(): string
    {
        return (new \DateTime())->format(DATE_ATOM) . ': '
            . ($this->dryRun === true ? '(dry-run) ' : '');
    }
}
```

- [ ] **Step 5: Draai de test, verwacht PASS**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install --no-interaction --quiet && vendor/bin/phpunit --filter CliOutputTest"`
Expected: `OK (7 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add src/Output/TaskOutput.php src/Output/CliOutput.php tests/Output/CliOutputTest.php
git commit -m "feat: semantic task output layer with CLI renderer"
```

---

### Task 3: HtmlOutput (TDD)

**Files:**
- Create: `src/Output/HtmlOutput.php`
- Test: `tests/Output/HtmlOutputTest.php`

- [ ] **Step 1: Schrijf de falende test**

```php
<?php

namespace Sweeper\Tests\Output;

use PHPUnit\Framework\TestCase;
use Sweeper\Output\HtmlOutput;

class HtmlOutputTest extends TestCase
{
    private function render(callable $calls, ?bool $dryRun = true): string
    {
        ob_start();
        $out = new HtmlOutput('Test task', $dryRun);
        $calls($out);
        return ob_get_clean();
    }

    public function testHeaderHasBadgeAndStyles(): void
    {
        $html = $this->render(fn ($out) => $out->finish());

        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('DRY-RUN', $html);
        $this->assertStringContainsString('Test task', $html);
    }

    public function testNullDryRunHasNoBadge(): void
    {
        $html = $this->render(fn ($out) => $out->finish(), null);

        $this->assertStringNotContainsString('sweeper-badge', $html);
    }

    public function testSectionIsCollapsibleWithCount(): void
    {
        $html = $this->render(function ($out) {
            $out->section('Droppable tables', 73);
            $out->items(['old_a']);
            $out->finish();
        });

        $this->assertStringContainsString('<details class="sweeper-section" open>', $html);
        $this->assertStringContainsString('<span class="sweeper-count">73</span>', $html);
        $this->assertStringContainsString('<li>old_a</li>', $html);
    }

    public function testCollapsedSectionHasNoOpenAttribute(): void
    {
        $html = $this->render(function ($out) {
            $out->section('Per class', null, false);
            $out->finish();
        });

        $this->assertStringContainsString('<details class="sweeper-section">', $html);
    }

    public function testSectionsAutoClose(): void
    {
        $html = $this->render(function ($out) {
            $out->section('One');
            $out->section('Two');
            $out->summary(['A' => 1]);
            $out->finish();
        });

        $this->assertSame(2, substr_count($html, '</details>'));
    }

    public function testEverythingIsEscaped(): void
    {
        $html = $this->render(function ($out) {
            $out->line('<script>alert(1)</script>');
            $out->items(['<b>x</b>']);
            $out->table(['<i>h</i>'], [['<u>c</u>']]);
            $out->finish();
        });

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringNotContainsString('<u>c</u>', $html);
    }

    public function testActionHasCopyButton(): void
    {
        $html = $this->render(fn ($out) => $out->action('CLI', 'run=yes token=abc'));

        $this->assertStringContainsString('run=yes token=abc', $html);
        $this->assertStringContainsString('navigator.clipboard.writeText', $html);
    }

    public function testWarningRendersAsCard(): void
    {
        $html = $this->render(fn ($out) => $out->warning('REFUSED'));

        $this->assertStringContainsString('sweeper-card warn', $html);
        $this->assertStringContainsString('REFUSED', $html);
    }
}
```

- [ ] **Step 2: Draai de test, verwacht FAIL**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install --no-interaction --quiet && vendor/bin/phpunit --filter HtmlOutputTest"`
Expected: Error "Class Sweeper\Output\HtmlOutput not found".

- [ ] **Step 3: Schrijf `src/Output/HtmlOutput.php`**

```php
<?php

namespace Sweeper\Output;

use SilverStripe\Core\Convert;

class HtmlOutput extends TaskOutput
{
    private bool $sectionOpen = false;

    protected function header(string $title): void
    {
        echo $this->styles();
        echo '<div class="sweeper-report">';
        echo '<h1>' . $this->esc($title);

        if ($this->dryRun !== null) {
            echo ' <span class="sweeper-badge ' . ($this->dryRun ? 'dry' : 'exec') . '">'
                . ($this->dryRun ? 'DRY-RUN' : 'EXECUTE') . '</span>';
        }

        echo '</h1>';
        echo '<p class="sweeper-meta">Started: ' . (new \DateTime())->format(DATE_ATOM) . '</p>';
    }

    public function line(string $message): void
    {
        echo '<p>' . $this->esc($message) . '</p>';
    }

    public function info(string $message): void
    {
        echo '<div class="sweeper-card info">' . $this->esc($message) . '</div>';
    }

    public function warning(string $message): void
    {
        echo '<div class="sweeper-card warn">' . $this->esc($message) . '</div>';
    }

    public function section(string $title, ?int $count = null, bool $open = true): void
    {
        $this->closeSection();
        $this->sectionOpen = true;

        echo '<details class="sweeper-section"' . ($open ? ' open' : '') . '>'
            . '<summary>' . $this->esc($title)
            . ($count !== null ? ' <span class="sweeper-count">' . $count . '</span>' : '')
            . '</summary>';
    }

    public function items(array $items): void
    {
        echo '<ul>';
        foreach ($items as $item) {
            echo '<li>' . $this->esc((string)$item) . '</li>';
        }
        echo '</ul>';
    }

    public function table(array $headers, array $rows): void
    {
        echo '<table><thead><tr>';
        foreach ($headers as $header) {
            echo '<th>' . $this->esc((string)$header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . $this->esc((string)$cell) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    public function summary(array $stats): void
    {
        $this->closeSection();

        echo '<div class="sweeper-summary"><h2>Summary</h2><table>';
        foreach ($stats as $label => $value) {
            echo '<tr><th>' . $this->esc((string)$label) . '</th>'
                . '<td>' . $this->esc((string)$value) . '</td></tr>';
        }
        echo '</table></div>';
    }

    public function action(string $label, string $command): void
    {
        $id = 'sweeper-action-' . substr(md5($command), 0, 8);

        echo '<div class="sweeper-action"><span>' . $this->esc($label) . '</span>'
            . '<code id="' . $id . '">' . $this->esc($command) . '</code>'
            . '<button type="button" onclick="navigator.clipboard.writeText('
            . "document.getElementById('" . $id . "').textContent)\">Copy</button></div>";
    }

    public function finish(): void
    {
        $this->closeSection();
        echo '<p class="sweeper-meta">Finished: ' . (new \DateTime())->format(DATE_ATOM) . '</p>';
        echo '</div>';
    }

    private function closeSection(): void
    {
        if ($this->sectionOpen) {
            echo '</details>';
            $this->sectionOpen = false;
        }
    }

    private function esc(string $value): string
    {
        return Convert::raw2xml($value);
    }

    private function styles(): string
    {
        return <<<'HTML'
            <style>
            .sweeper-report{font-family:system-ui,-apple-system,sans-serif;max-width:960px;margin:1rem auto;color:#0f172a}
            .sweeper-report h1{font-size:1.4rem}
            .sweeper-badge{font-size:.7rem;font-weight:700;padding:.2rem .55rem;border-radius:999px;vertical-align:middle}
            .sweeper-badge.dry{background:#fef3c7;color:#92400e}
            .sweeper-badge.exec{background:#fee2e2;color:#991b1b}
            .sweeper-meta{color:#64748b;font-size:.8rem}
            .sweeper-section{border:1px solid #e2e8f0;border-radius:8px;padding:.5rem .75rem;margin:.5rem 0}
            .sweeper-section summary{cursor:pointer;font-weight:600}
            .sweeper-count{background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:0 .5rem;font-size:.8rem}
            .sweeper-card{border-left:4px solid;padding:.5rem .75rem;margin:.5rem 0;border-radius:0 6px 6px 0}
            .sweeper-card.info{border-color:#2563eb;background:#eff6ff}
            .sweeper-card.warn{border-color:#dc2626;background:#fef2f2}
            .sweeper-report table{border-collapse:collapse;margin:.25rem 0}
            .sweeper-report th,.sweeper-report td{border:1px solid #e2e8f0;padding:.25rem .6rem;text-align:left;font-size:.85rem;vertical-align:top}
            .sweeper-summary{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.5rem .75rem;margin:.75rem 0}
            .sweeper-summary h2{font-size:1rem;margin:.25rem 0}
            .sweeper-action{display:flex;gap:.75rem;align-items:center;background:#0f172a;color:#e2e8f0;border-radius:8px;padding:.5rem .75rem;margin:.5rem 0}
            .sweeper-action code{color:#86efac;background:transparent}
            .sweeper-action button{margin-left:auto;cursor:pointer}
            </style>
            HTML;
    }
}
```

- [ ] **Step 4: Draai alle tests, verwacht PASS**

Run: `docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install --no-interaction --quiet && vendor/bin/phpunit"`
Expected: alle tests groen (CliOutputTest + HtmlOutputTest + SchemaDiffTest).

- [ ] **Step 5: Commit**

```bash
git add src/Output/HtmlOutput.php tests/Output/HtmlOutputTest.php
git commit -m "feat: HTML report renderer with collapsible sections and copy action"
```

---

### Task 4: SchemaArtefactsTask integreren (+ token-gelijkheid bewijzen)

**Files:**
- Modify: `src/Tasks/SchemaArtefactsTask.php`

- [ ] **Step 1: Leg het huidige token vast (vóór de wijziging)**

```bash
cd "/Users/jeroen/Development/klanten/Olympia/website"
docker compose -f compose.yml -f compose.override.yaml -f compose.sweeper.yml exec -T php ./vendor/bin/sake dev/tasks/sweeper-schema-artefacts 2>&1 | grep -oE "token=[a-f0-9]+" | head -1 > /tmp/token_before.txt
cat /tmp/token_before.txt
```

Expected: één regel `token=<10 hex chars>`.

- [ ] **Step 2: Vervang in `SchemaArtefactsTask` de output-aanroepen**

Wijzigingen in `src/Tasks/SchemaArtefactsTask.php`:

1. Imports: verwijder `use SilverStripe\Control\Director;`, voeg toe `use Sweeper\Output\TaskOutput;`.
2. Voeg property toe en vervang `run()` volledig; verwijder de `log()`-methode helemaal.

```php
    private bool $dryRun = true;

    private TaskOutput $out;

    public function run($request): int
    {
        $this->dryRun = !($request->getVar('run') === 'yes');
        $this->out = TaskOutput::create('Sweeper: schema artefacts', $this->dryRun);

        $connection = DB::get_conn();
        if (!$connection || !$connection->isActive()) {
            $this->out->warning('No active database connection found');
            $this->out->finish();
            return 1;
        }

        $this->out->line('Reading current database schema');
        $current = $this->captureCurrentSchema();

        $this->out->line('Recording reference schema (no temp database)');
        try {
            $clean = $this->captureReferenceSchema();
        } catch (\Throwable $e) {
            // A partial reference schema is unsafe to diff (it would flag real
            // artefacts as orphaned), so abort without changing anything.
            $this->out->warning('Failed to record reference schema: ' . $e->getMessage());
            $this->out->info('Aborting without changes.');
            $this->out->finish();
            return 1;
        }

        $this->out->line(count($clean) . ' reference tables recorded, ' . count($current) . ' tables in database');
        $this->out->line('Comparing schemas');

        $droppable = SchemaDiff::diff($current, $clean);
        $hasDroppables = $droppable['tables'] || $droppable['columns'] || $droppable['indexes'];
        $token = SchemaDiff::confirmationToken($droppable);

        if (!$this->dryRun && $hasDroppables) {
            $given = (string)$request->getVar('token');
            if (!hash_equals($token, $given)) {
                $this->out->warning('REFUSED: missing or stale confirmation token.');
                $this->out->info(
                    'Run this task without run=yes first and review its output; it prints the token to use. '
                    . 'A previously valid token means the droppable set changed since your review '
                    . '(deploy, dev/build or manual schema change). Review again.'
                );
                $this->out->finish();
                return 1;
            }
        }

        $this->dropTables($droppable['tables']);
        $this->dropColumns($droppable['columns']);
        $this->dropIndexes($droppable['indexes']);

        $this->out->summary([
            'Tables' => count($droppable['tables']),
            'Columns' => array_sum(array_map('count', $droppable['columns'])),
            'Indexes' => array_sum(array_map('count', $droppable['indexes'])),
            'Mode' => $this->dryRun ? 'dry-run (nothing changed)' : 'executed',
        ]);

        if ($this->dryRun && $hasDroppables) {
            $this->out->action('CLI', "vendor/bin/sake dev/tasks/sweeper-schema-artefacts run=yes token={$token}");
            $this->out->action('URL', "/dev/tasks/sweeper-schema-artefacts?run=yes&token={$token}");
        }

        $this->out->finish();
        return 0;
    }
```

3. Vervang de drie drop-methodes volledig:

```php
    private function dropTables(array $tables): void
    {
        if (!$tables) {
            $this->out->line('No droppable tables');
            return;
        }

        $this->out->section('Droppable tables', count($tables));
        $this->out->items($tables);

        if ($this->dryRun) {
            return;
        }

        foreach ($tables as $table) {
            DB::query("DROP TABLE IF EXISTS \"$table\"");
        }
    }

    private function dropColumns(array $columnsByTable): void
    {
        if (!$columnsByTable) {
            $this->out->line('No droppable columns');
            return;
        }

        $rows = [];
        foreach ($columnsByTable as $table => $columns) {
            $rows[] = [$table, implode(', ', $columns)];
        }

        $this->out->section('Droppable columns', array_sum(array_map('count', $columnsByTable)));
        $this->out->table(['Table', 'Columns'], $rows);

        if ($this->dryRun) {
            return;
        }

        foreach ($columnsByTable as $table => $columns) {
            foreach ($columns as $column) {
                DB::query("ALTER TABLE \"$table\" DROP COLUMN \"$column\"");
            }
        }
    }

    private function dropIndexes(array $indexesByTable): void
    {
        if (!$indexesByTable) {
            $this->out->line('No droppable indexes');
            return;
        }

        $rows = [];
        foreach ($indexesByTable as $table => $indexes) {
            $rows[] = [$table, implode(', ', $indexes)];
        }

        $this->out->section('Droppable indexes', array_sum(array_map('count', $indexesByTable)));
        $this->out->table(['Table', 'Indexes'], $rows);

        if ($this->dryRun) {
            return;
        }

        foreach ($indexesByTable as $table => $indexes) {
            foreach ($indexes as $index) {
                // Defence in depth: the diff already excludes PRIMARY.
                if (strtoupper((string)$index) === 'PRIMARY') {
                    continue;
                }
                DB::query("DROP INDEX \"$index\" ON \"$table\"");
            }
        }
    }
```

Let op: de docblock-vermelding van de output blijft kloppen; werk de `$description`-tekst NIET om (functioneel ongewijzigd).

- [ ] **Step 3: Token-gelijkheid en weigerpaden verifiëren op Olympia**

```bash
cd "/Users/jeroen/Development/klanten/Olympia/website"
docker compose -f compose.yml -f compose.override.yaml -f compose.sweeper.yml exec -T php ./vendor/bin/sake dev/tasks/sweeper-schema-artefacts 2>&1 | grep -oE "token=[a-f0-9]+" | head -1 > /tmp/token_after.txt
diff /tmp/token_before.txt /tmp/token_after.txt && echo "TOKEN GELIJK (goed)"
docker compose -f compose.yml -f compose.override.yaml -f compose.sweeper.yml exec -T php ./vendor/bin/sake dev/tasks/sweeper-schema-artefacts run=yes 2>&1 | grep -o "REFUSED" | head -1
```

Expected: `TOKEN GELIJK (goed)` en `REFUSED`. Token ongelijk = de refactor heeft de droppable-set geraakt: STOP en zoek de oorzaak (waarschijnlijk een wijziging in diff-aanroep of volgorde).

- [ ] **Step 4: Web-output visueel controleren**

```bash
curl -sk "https://localhost:17080/dev/tasks/sweeper-schema-artefacts" -o /tmp/schema_web.html
grep -c "sweeper-section" /tmp/schema_web.html
open /tmp/schema_web.html
```

Expected: minimaal 3 secties; in de browser: titelbalk met DRY-RUN-badge, inklapbare secties, tabellen, summary-blok, twee action-blokken met Copy-knop.

- [ ] **Step 5: Commit**

```bash
cd "/Users/jeroen/Development/klanten/WeDevelop/modules/silverstripe/silverstripe-sweeper"
git add src/Tasks/SchemaArtefactsTask.php
git commit -m "feat: schema artefacts task uses shared output layer"
```

---

### Task 5: SweeperReportTask integreren

**Files:**
- Modify: `src/Tasks/SweeperReportTask.php`

- [ ] **Step 1: Vervang `run()` volledig**

Voeg import toe: `use Sweeper\Output\TaskOutput;`

```php
    public function run($request): void
    {
        $filterSilverstripeClasses = $request->requestVar('no-silverstripe-filter') === null;
        $filterSpecificNamespace = $request->requestVar('namespace-filter');

        $out = TaskOutput::create('Sweeper: report', null);
        $out->info(
            'Filters: '
            . ($filterSilverstripeClasses ? 'SilverStripe\\ classes hidden' : 'SilverStripe\\ classes included')
            . ($filterSpecificNamespace ? ", namespace contains \"{$filterSpecificNamespace}\"" : '')
        );

        $dataObjectSubclasses = ClassInfo::subclassesFor(DataObject::class);
        $dataExtensionSubclasses = array_values(ClassInfo::subclassesFor(DataExtension::class));

        $withoutInstances = [];
        foreach ($dataObjectSubclasses as $dataObjectClass) {
            if ($dataObjectClass === DataObject::class) {
                continue;
            }

            if ($filterSilverstripeClasses && str_contains($dataObjectClass, 'SilverStripe\\')) {
                continue;
            }

            if ($filterSpecificNamespace && !str_contains($dataObjectClass, $filterSpecificNamespace)) {
                continue;
            }

            if ($dataObjectClass::get()->count() === 0) {
                $withoutInstances[] = $dataObjectClass;
            }
        }

        $out->section('DataObjects without instances', count($withoutInstances));
        if ($withoutInstances) {
            $out->items($withoutInstances);
        } else {
            $out->line('Every DataObject has at least one instance.');
        }

        $appliedDataExtensions = [];
        foreach ($dataObjectSubclasses as $dataObjectClass) {
            /** @var DataObject $singleton */
            $singleton = $dataObjectClass::singleton();
            $appliedExtensions = $singleton->getExtensionInstances();

            foreach ($appliedExtensions as $extension) {
                $className = get_class($extension);

                if (!in_array($className, $dataExtensionSubclasses, true)) {
                    continue;
                }

                $appliedDataExtensions[] = $className;
            }
        }

        $appliedDataExtensions = array_unique($appliedDataExtensions);
        $dataExtensionDiff = array_values(array_filter(
            array_diff($dataExtensionSubclasses, $appliedDataExtensions),
            static function ($className) use ($filterSilverstripeClasses, $filterSpecificNamespace) {
                if ($filterSilverstripeClasses && $filterSpecificNamespace) {
                    return !str_contains($className, 'SilverStripe\\') && str_contains($className, $filterSpecificNamespace);
                }

                if ($filterSilverstripeClasses) {
                    return !str_contains($className, 'SilverStripe\\');
                }

                if ($filterSpecificNamespace) {
                    return str_contains($className, $filterSpecificNamespace);
                }

                return true;
            }
        ));

        $out->section('DataExtensions never applied', count($dataExtensionDiff));
        if ($dataExtensionDiff) {
            $out->items($dataExtensionDiff);
            $out->info(
                'NOTE: A DataExtension could be listed even though you have it applied somewhere; this is '
                . 'most likely a case of a DataExtension that can safely extend Extension instead. You can at '
                . 'least safely conclude that there are no subclasses of DataObject with that extension.'
            );
        } else {
            $out->line('All DataExtensions are applied at least once.');
        }

        $out->summary([
            'DataObjects without instances' => count($withoutInstances),
            'DataExtensions never applied' => count($dataExtensionDiff),
        ]);
        $out->finish();
    }
```

(De filterlogica is byte-voor-byte de bestaande logica; alleen verzameld in arrays in plaats van direct ge-echo'd.)

- [ ] **Step 2: Verifieer op Olympia (CLI en web)**

```bash
cd "/Users/jeroen/Development/klanten/Olympia/website"
docker compose -f compose.yml -f compose.override.yaml -f compose.sweeper.yml exec -T php ./vendor/bin/sake dev/tasks/sweeper-report 2>&1 | tail -15
curl -sk "https://localhost:17080/dev/tasks/sweeper-report" -o /tmp/report_web.html && open /tmp/report_web.html
```

Expected CLI: twee secties met underline, lijsten, summary-kader; totalen gelijk aan de eerdere run (14 DataObjects / 17 extensions, mits DB ongewijzigd). Web: nette kaarten/secties, geen badge (read-only).

- [ ] **Step 3: Commit**

```bash
cd "/Users/jeroen/Development/klanten/WeDevelop/modules/silverstripe/silverstripe-sweeper"
git add src/Tasks/SweeperReportTask.php
git commit -m "feat: report task uses shared output layer"
```

---

### Task 6: SweeperClearArchiveTask integreren

**Files:**
- Modify: `src/Tasks/SweeperClearArchiveTask.php`

Kernpunten: output-property + sectie per class (dichtgeklapt), de drie delete-methodes geven hun aantal terug voor de summary-totalen, het oude `message()` en de handmatige `(dry-run): `-prefixen verdwijnen (de output-laag prefixt zelf).

- [ ] **Step 1: Imports en properties aanpassen**

Verwijder imports `SilverStripe\Control\Director`, `SilverStripe\Core\Convert`, `SilverStripe\View\HTML`. Voeg toe: `use Sweeper\Output\TaskOutput;`

Voeg properties toe na `protected bool $fast = false;`:

```php
    private TaskOutput $output;

    private int $totalDraftVersionsCleared = 0;

    private int $totalArchivedVersionsCleared = 0;

    private int $totalOrphanedRowsCleared = 0;

    private int $totalSnapshotsCleared = 0;
```

- [ ] **Step 2: Vervang `run()` en `flushClass()`; verwijder `message()`**

```php
    public function run($request): void
    {
        $run = $request->getVar('run');
        if (!in_array($run, ['dry', 'yes', 'fast'], true)) {
            throw new InvalidArgumentException("Please provide the 'run' argument with either 'yes', 'dry', or 'fast'");
        }
        $this->setDry($run === 'dry');
        $this->setFast($run === 'fast');

        $this->output = TaskOutput::create('Sweeper: clear archive', $this->isDry());

        // With slow requests, need to increase time limit to 1 hour
        if (!$this->isFast() && !$this->isDry()) {
            Environment::increaseTimeLimitTo(3600);
            Environment::increaseMemoryLimitTo();
        }

        // Set keep versions
        $this->setKeepVersions((int)$request->getVar('keep') ?: (int)self::config()->get('keep'));
        $this->output->line('Keeping the last ' . $this->getKeepVersions() . ' versions per record');

        // Loop over all versioned classes
        foreach ($this->getBaseVersionedClasses() as $class) {
            $this->output->section($class, null, false);

            if (self::hasSnapshots() && !$this->isFast()) {
                $this->flushSnapshots($class);
            }

            $this->flushClass($class);
        }

        $this->output->summary([
            'Draft versions cleared' => $this->totalDraftVersionsCleared,
            'Archived versions cleared' => $this->totalArchivedVersionsCleared,
            'Orphaned rows cleared' => $this->totalOrphanedRowsCleared,
            'Snapshots cleared' => $this->totalSnapshotsCleared,
            'Versions kept per record' => $this->getKeepVersions(),
            'Mode' => $run,
        ]);
        $this->output->finish();
    }

    public function flushClass(string $class): void
    {
        // Delete old versions for non-deleted records (note: Can be slow on large recordsets)
        if (!$this->isFast()) {
            $this->totalDraftVersionsCleared += $this->deleteOldVersions($class);
        }

        // Clear all obsolete versions for deleted records
        $this->totalArchivedVersionsCleared += $this->deleteArchivedVersionsWithVersionRetention($class);

        // Flush all subclass tables
        $this->totalOrphanedRowsCleared += $this->deleteOrphanedVersions($class);
    }
```

- [ ] **Step 3: Laat de delete-methodes hun aantal teruggeven**

In `deleteArchivedVersionsWithVersionRetention()`: signature wordt `public function deleteArchivedVersionsWithVersionRetention(string $class): int`. Vervang het log-blok onderaan door:

```php
        if ($clearedVersionCounts) {
            $this->output->line(
                "Cleared {$clearedVersionCounts} old archived versions (before last {$this->getKeepVersions()}) from table {$baseVersionedTable}"
            );
        }

        return $clearedVersionCounts;
```

In `deleteOldVersions()`: signature wordt `public function deleteOldVersions(string $class): int`. Vervang het log-blok onderaan door:

```php
        if ($clearedVersionCounts) {
            $this->output->line(
                "Cleared {$clearedVersionCounts} old versions (before last {$this->getKeepVersions()}) from table {$baseVersionedTable}"
            );
        }

        return $clearedVersionCounts;
```

In `deleteOrphanedVersions()`: signature wordt `public function deleteOrphanedVersions(string $class): int`. Voeg bovenaan `$totalCleared = 0;` toe; in de lus vervang het log-blok door:

```php
            if ($count) {
                $this->output->line("Cleared {$count} rows from {$versionedTable}");
                $totalCleared += $count;
            }
```

en sluit de methode af met `return $totalCleared;`.

In `deleteArchivedVersions()` (de ongebruikte methode): vervang de `$this->message(...)`-aanroep door `$this->output->line("Cleared {$count} rows from {$baseVersionedTable} for deleted records");` zodat er geen dode verwijzing blijft. (Verwijderen van deze methode is bewust buiten scope.)

- [ ] **Step 4: `flushSnapshots()` aanpassen**

Vervang alle `$this->message($prefix . ...)`-aanroepen; de `$prefix`-variabele en de eerste twee regels van de methode vervallen:

```php
    public function flushSnapshots(string $class): void
    {
        $this->output->line("Beginning snapshot flush for {$class}");
        $objects = $class::get();

        $totalClearedSnapshotCount = 0;
        $totalKeptSnapshotCount = 0;
        foreach ($objects as $object) {
            try {
                // ... (bestaande lus ongewijzigd t/m de twee tellers) ...

                $totalClearedSnapshotCount += $clearedSnapshotCounts;
                $totalKeptSnapshotCount += $keptSnapshotCounts;
            } catch (\Exception $e) {
                $this->output->warning("Exception during parsing of object {$class}: {$object->ID} ({$e->getMessage()})");
            }
        }

        $this->output->line("Cleared {$totalClearedSnapshotCount} snapshots for {$class} (kept {$totalKeptSnapshotCount})");
        $this->totalSnapshotsCleared += $totalClearedSnapshotCount;
    }
```

Let op: de twee per-object `message()`-regels ("Cleared/Kept ... for $class: $object->ID") vervallen bewust; bij grote sites zijn dat duizenden regels ruis. De totalen per class blijven.

- [ ] **Step 5: Verifieer op Olympia (dry-run)**

```bash
cd "/Users/jeroen/Development/klanten/Olympia/website"
docker compose -f compose.yml -f compose.override.yaml -f compose.sweeper.yml exec -T php ./vendor/bin/sake dev/tasks/sweeper-archive run=dry 2>&1 | tail -20
curl -sk "https://localhost:17080/dev/tasks/sweeper-archive?run=dry" -o /tmp/archive_web.html && open /tmp/archive_web.html
```

Expected CLI: sectie per versioned class, regels met aantallen, summary-kader met de vijf totalen + Mode: dry. Web: dichtgeklapte secties per class (geen `open`-attribuut), DRY-RUN-badge. `run=dry` wijzigt niets aan de database.

- [ ] **Step 6: Commit**

```bash
cd "/Users/jeroen/Development/klanten/WeDevelop/modules/silverstripe/silverstripe-sweeper"
git add src/Tasks/SweeperClearArchiveTask.php
git commit -m "feat: clear-archive task uses shared output layer with per-class sections"
```

---

### Task 7: Oude `sweeper-artefacts` depreceren

**Files:**
- Modify: `src/Tasks/SweeperArtefactsTask.php` (de oude, `App\Tasks\SweeperArtefacts`)

Geen output-rework; alleen markering. De klasse houdt zijn eigen `log()`.

- [ ] **Step 1: Titel en description aanpassen**

Voeg direct onder `private static bool $dryRun = true;` toe:

```php
    protected $title = 'DEPRECATED: use sweeper-schema-artefacts';
```

Vervang de eerste regel van de `$description`-heredoc zodat die begint met:

```
        DEPRECATED: superseded by dev/tasks/sweeper-schema-artefacts, which needs
        no CREATE DATABASE privilege and adds a confirmation token. This task
        builds a clean schema in a temporary database (requires CREATE/DROP
        DATABASE rights on the server).
```

(gevolgd door de bestaande beschrijvingstekst).

- [ ] **Step 2: Waarschuwing bij run**

Voeg als eerste regels in `run()` toe (na de `dryRun`-toekenning):

```php
        $deprecation = 'DEPRECATED: this task is superseded by dev/tasks/sweeper-schema-artefacts '
            . '(no CREATE DATABASE privilege needed, adds a confirmation token).';
        echo \SilverStripe\Control\Director::is_cli()
            ? "!! {$deprecation}\n\n"
            : '<p style="border-left:4px solid #dc2626;background:#fef2f2;padding:.5rem .75rem">' . $deprecation . '</p>';
```

- [ ] **Step 3: Verifieer en commit**

```bash
cd "/Users/jeroen/Development/klanten/Olympia/website"
docker compose -f compose.yml -f compose.override.yaml -f compose.sweeper.yml exec -T php ./vendor/bin/sake dev/tasks/sweeper-artefacts 2>&1 | head -3
```

Expected: de eerste regel bevat `!! DEPRECATED`, daarna gewoon de bestaande dry-run-output.

```bash
cd "/Users/jeroen/Development/klanten/WeDevelop/modules/silverstripe/silverstripe-sweeper"
git add src/Tasks/SweeperArtefactsTask.php
git commit -m "chore: deprecate sweeper-artefacts in favour of sweeper-schema-artefacts"
```

---

### Task 8: README en CLAUDE.md bijwerken

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Vervang in `README.md` de sectie `### sweeper-artefacts` door**

```markdown
### sweeper-schema-artefacts

Finds (and optionally removes) orphaned tables, columns and indexes by recording
the schema SilverStripe would build (the same `requireTable()`/`augmentDatabase()`
path as `dev/build`) and diffing it against the live database. Requires **no**
`CREATE DATABASE` privilege and no temporary database.

Workflow:

1. Run `dev/tasks/sweeper-schema-artefacts` (dry-run by default). Review the
   report; it ends with a confirmation token.
2. Run `dev/tasks/sweeper-schema-artefacts?run=yes&token=<token>` to execute
   exactly the reviewed set. A stale token (schema changed since your review) is
   refused.

The PRIMARY key is never dropped. Indexes are matched by signature
(type + columns), so engine-generated index names are handled correctly.

NOTE: anything in the database that is not part of the SilverStripe schema WILL
be reported, and removed when executed.

### sweeper-artefacts (deprecated)

Superseded by `sweeper-schema-artefacts`. This older variant builds the clean
schema in a temporary database and therefore requires `CREATE DATABASE`/`DROP
DATABASE` rights on the server, which managed hosting typically does not grant.
```

- [ ] **Step 2: Werk `CLAUDE.md` bij**

1. In de `## Commands`-sectie: verwijder de bewering dat er geen `phpunit.xml` is; vervang de phpunit-regels door:

```markdown
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install --no-interaction --quiet && vendor/bin/phpunit"   # tests (host has no PHP)
```

2. In `## Architecture`: voeg een kopje toe na de bestaande taakbeschrijvingen:

```markdown
### sweeper-schema-artefacts (`SchemaArtefactsTask`)
Successor to sweeper-artefacts. Records the reference schema by temporarily
swapping in `Sweeper\Schema\RecordingSchemaManager` (intercepts `createTable()`
during the standard `requireTable()`/`augmentDatabase()` traversal), diffs via
the pure `Sweeper\Schema\SchemaDiff` (tables/columns by name, case-insensitive;
indexes by type+columns signature), and gates `run=yes` behind a confirmation
token (hash of the droppable set). No `CREATE DATABASE` privilege needed.

### Output layer (`src/Output/`)
All active tasks render through `Sweeper\Output\TaskOutput` (factory picks
`CliOutput` or `HtmlOutput` via `Director::is_cli()`). Tasks call semantic
methods (`section`, `items`, `table`, `summary`, `action`); renderers are pure
and unit-tested. Streaming: summary always comes last.
```

3. In `## Gotchas`: de PSR-4-gotcha blijft (oude taak is alleen deprecated, niet hernoemd); voeg toe:

```markdown
- The module is bind-mounted into a host project for live testing (see
  `compose.sweeper.yml` in the host); its nested `vendor/` is ignored by the
  framework's `ManifestFileFinder`, so it does not pollute the host manifest.
```

- [ ] **Step 3: Commit**

```bash
git add README.md CLAUDE.md
git commit -m "docs: document schema-artefacts task, deprecation and output layer"
```

---

### Task 9: Eindverificatie (Olympia, browser + CLI)

**Files:** geen.

- [ ] **Step 1: Alle unit tests**

```bash
cd "/Users/jeroen/Development/klanten/WeDevelop/modules/silverstripe/silverstripe-sweeper"
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install --no-interaction --quiet && vendor/bin/phpunit"
```

Expected: alles groen.

- [ ] **Step 2: Lint**

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "vendor/bin/phpcs || true; vendor/bin/phpstan analyse --no-progress || true"
```

Expected: geen nieuwe violations in `src/Output/` en de gewijzigde taken (bestaande violations in ongerelateerde code mogen blijven).

- [ ] **Step 3: Volledige functionele pas op Olympia**

```bash
cd "/Users/jeroen/Development/klanten/Olympia/website"
C="docker compose -f compose.yml -f compose.override.yaml -f compose.sweeper.yml"
$C exec -T php ./vendor/bin/sake dev/build flush=1 2>&1 | tail -2
$C exec -T php ./vendor/bin/sake dev/tasks/sweeper-schema-artefacts 2>&1 | tail -12
$C exec -T php ./vendor/bin/sake dev/tasks/sweeper-schema-artefacts run=yes 2>&1 | grep REFUSED
$C exec -T php ./vendor/bin/sake dev/tasks/sweeper-report 2>&1 | tail -8
$C exec -T php ./vendor/bin/sake dev/tasks/sweeper-archive run=dry 2>&1 | tail -10
$C exec -T php ./vendor/bin/sake dev/tasks/sweeper-artefacts 2>&1 | head -2
```

Expected, in volgorde: succesvolle build; schema-taak eindigt met summary + twee action-regels; REFUSED zonder token; report eindigt met summary; archive eindigt met summary; oude taak begint met `!! DEPRECATED`.

NB: het `$C`-alias werkt in bash; voer in zsh de commando's desnoods voluit in.

- [ ] **Step 4: Browser-check (handmatig)**

Open en beoordeel visueel:
- `https://localhost:17080/dev/tasks/sweeper-schema-artefacts`
- `https://localhost:17080/dev/tasks/sweeper-report`
- `https://localhost:17080/dev/tasks/sweeper-archive?run=dry`

Checklist: badge correct (DRY-RUN / geen / DRY-RUN), secties inklapbaar, tabellen leesbaar, summary onderaan, Copy-knop werkt (schema-taak), archive-secties dichtgeklapt.

- [ ] **Step 5: Tabel-telling onveranderd (niets per ongeluk gedropt)**

```bash
docker compose -f compose.yml -f compose.override.yaml -f compose.sweeper.yml exec -T mysql sh -c 'mysql -uroot -ppassword silverstripe -N -e "SHOW TABLES"' | wc -l
```

Expected: zelfde aantal als vóór het plan (384, mits de DB niet bewust gewijzigd is).

---

## Self-review (uitgevoerd bij het schrijven)

- **Spec-dekking**: output-API + twee renderers (Task 2-3), integratie per actieve taak (Task 4-6), deprecation (Task 7), README (Task 8), verificatie incl. token-gelijkheid (Task 4/9). Alle spec-secties gedekt.
- **Geen placeholders**: elke stap bevat concrete code of exacte commando's; de ene uitzondering (bestaande snapshot-lus "ongewijzigd t/m de tellers") verwijst naar code die in de huidige file staat en niet wijzigt.
- **Type-consistentie**: `TaskOutput::create(string, ?bool)`, `section(string, ?int, bool)`, delete-methodes `: int`; overal gelijk gebruikt.
