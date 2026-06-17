# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`wedevelopnl/silverstripe-sweeper` is a SilverStripe **vendor module** (`type: silverstripe-vendormodule`, framework `^5.0`). It ships three `BuildTask`s that clean up long-running SilverStripe projects. It is not a standalone application: the tasks only run inside a host SilverStripe project with a configured database. There is nothing to "run" from this repo in isolation.

## Commands

This repo has a `phpunit.xml` but no lockfile or `phpcs.xml` of its own; tooling is pulled in via `require-dev` and the host project / shared standards. The host machine has no PHP, so run everything through Docker:

```bash
# tests (composer:2 image; --ignore-platform-reqs because the image lacks ext-intl)
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install --no-interaction --ignore-platform-reqs --quiet && vendor/bin/phpunit"
docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit --filter testMethodName   # single test
docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpstan analyse   # static analysis (phpstan.neon, src/ only)
docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpcs             # lint
```

The phpstan SilverStripe extension is auto-registered via `phpstan/extension-installer`; no manual `includes` are needed in `phpstan.neon`.

## Running the tasks

Tasks are invoked in the **host project** by their `$segment`, not their class name:

```bash
# CLI
vendor/bin/sake dev/tasks/sweeper-schema-artefacts
vendor/bin/sake dev/tasks/sweeper-archive run=dry keep=20
vendor/bin/sake dev/tasks/sweeper-report namespace-filter=App

# or via browser: /dev/tasks/<segment>?<args>
```

All three tasks are **destructive by design** (except `sweeper-report`). Default to dry-run while developing and verifying.

## Architecture

Three independent `BuildTask`s in `src/Tasks/`, discovered automatically by SilverStripe via their `private static $segment`. Each one is self-contained; there is no shared base class or service layer.

### sweeper-archive (`SweeperClearArchiveTask`)
Prunes `Versioned` history. Iterates the **direct** subclasses of `DataObject` that have the `Versioned` extension, and for each runs three operations against the `<Table>_Versions` tables: trim drafts to the last `keep` versions (batched in groups of 100 via a `MOD(ID)` filter to bound memory), prune archived/deleted records beyond `keep`, and delete orphaned subclass-table versions. Run modes via `run=`: `dry` (count only), `yes` (full, raises time/memory limits), `fast` (skips the slow per-draft trimming and snapshots). `keep` (default 10, set in `_config/config.yml` or `?keep=`) controls retention. If `silverstripe/versioned-snapshots` is installed, snapshot cleanup runs too (detected at runtime via `Composer\InstalledVersions`, so the dependency stays optional).

### sweeper-report (`SweeperReportTask`)
Read-only diagnostics for refactoring: lists `DataObject` subclasses with zero instances, and `DataExtension` subclasses that are defined but never applied. Filters out the `SilverStripe\` namespace by default (`no-silverstripe-filter` disables this; `namespace-filter=<str>` restricts to classes whose name *contains* the string).

### sweeper-schema-artefacts (`SchemaArtefactsTask`)
Schema reconciliation without a temporary database. Records the reference schema by temporarily swapping in `Sweeper\Schema\RecordingSchemaManager` (intercepts `createTable()` during the standard `requireTable()`/`augmentDatabase()` traversal), diffs via the pure `Sweeper\Schema\SchemaDiff` (tables/columns by name, case-insensitive; indexes by type+columns signature), and gates `run=yes` behind a confirmation token (hash of the droppable set). No `CREATE DATABASE` privilege needed. Anything in the live DB that the recorded schema no longer defines is droppable; defaults to **dry-run**.

### Output layer (`src/Output/`)
All active tasks render through `Sweeper\Output\TaskOutput` (factory picks `CliOutput` or `HtmlOutput` via `Director::is_cli()`). Tasks call semantic methods (`section`, `items`, `table`, `summary`, `action`); renderers are pure and unit-tested. Streaming: summary always comes last.

## Gotchas

- `SweeperClearArchiveTask` contains an unused `deleteArchivedVersions()` method; `flushClass()` calls `deleteArchivedVersionsWithVersionRetention()` instead.
- For live testing the module is bind-mounted into a host project (e.g. `compose.sweeper.yml` in the Olympia project, mounted over `vendor/wedevelopnl/silverstripe-sweeper`). The module's own nested `vendor/` is ignored by the framework's `ManifestFileFinder`, so it does not pollute the host's class manifest.
