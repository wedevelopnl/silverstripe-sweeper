# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`wedevelopnl/silverstripe-sweeper` is a SilverStripe **vendor module** (`type: silverstripe-vendormodule`, framework `^5.0`). It ships three `BuildTask`s that clean up long-running SilverStripe projects. It is not a standalone application: the tasks only run inside a host SilverStripe project with a configured database. There is nothing to "run" from this repo in isolation.

## Commands

This repo has no lockfile, no `phpunit.xml`, and no `phpcs.xml` of its own; tooling is pulled in via `require-dev` and the host project / shared standards.

```bash
composer install                 # install dev tooling (phpunit, phpcs, phpstan, silverstripe/standards)
vendor/bin/phpstan analyse       # static analysis; config in phpstan.neon, analyses src/ only
vendor/bin/phpcs                 # lint (PSR + silverstripe/standards ruleset)
vendor/bin/phpcbf                # auto-fix lint violations
vendor/bin/phpunit               # run tests (none exist yet)
vendor/bin/phpunit --filter testMethodName   # run a single test
```

The phpstan SilverStripe extension is auto-registered via `phpstan/extension-installer`; no manual `includes` are needed in `phpstan.neon`.

## Running the tasks

Tasks are invoked in the **host project** by their `$segment`, not their class name:

```bash
# CLI
vendor/bin/sake dev/tasks/sweeper-artefacts run=yes
vendor/bin/sake dev/tasks/sweeper-archive run=dry keep=20
vendor/bin/sake dev/tasks/sweeper-report namespace-filter=App

# or via browser: /dev/tasks/<segment>?<args>
```

All three tasks are **destructive by design** (except `sweeper-report`). Default to dry-run while developing and verifying.

## Architecture

Three independent `BuildTask`s in `src/Tasks/`, discovered automatically by SilverStripe via their `private static $segment`. Each one is self-contained; there is no shared base class or service layer.

### sweeper-artefacts (`SweeperArtefacts`)
Schema reconciliation. Snapshots the live DB schema (tables/columns/indexes), then spins up a clean `TempDatabase`, builds the canonical schema from the current SilverStripe class definitions, and diffs the two. Anything in the live DB that the schema no longer defines is droppable. Defaults to **dry-run**; pass `run=yes` to actually `DROP`. Note the deliberate reconnect after `TempDatabase::kill()`: killing the temp DB disrupts the active connection, so the task re-selects the original database before continuing.

### sweeper-archive (`SweeperClearArchiveTask`)
Prunes `Versioned` history. Iterates the **direct** subclasses of `DataObject` that have the `Versioned` extension, and for each runs three operations against the `<Table>_Versions` tables: trim drafts to the last `keep` versions (batched in groups of 100 via a `MOD(ID)` filter to bound memory), prune archived/deleted records beyond `keep`, and delete orphaned subclass-table versions. Run modes via `run=`: `dry` (count only), `yes` (full, raises time/memory limits), `fast` (skips the slow per-draft trimming and snapshots). `keep` (default 10, set in `_config/config.yml` or `?keep=`) controls retention. If `silverstripe/versioned-snapshots` is installed, snapshot cleanup runs too (detected at runtime via `Composer\InstalledVersions`, so the dependency stays optional).

### sweeper-report (`SweeperReportTask`)
Read-only diagnostics for refactoring: lists `DataObject` subclasses with zero instances, and `DataExtension` subclasses that are defined but never applied. Filters out the `SilverStripe\` namespace by default (`no-silverstripe-filter` disables this; `namespace-filter=<str>` restricts to classes whose name *contains* the string).

## Gotchas

- **`src/Tasks/SweeperArtefactsTask.php` does not match PSR-4.** Autoload maps `Sweeper\` → `src/`, but this file declares `namespace App\Tasks` with class `SweeperArtefacts` (and the filename has a `Task` suffix the class lacks). The other two tasks correctly use `namespace Sweeper\Tasks` with matching filenames. Fixing the namespace/class/filename to align with `Sweeper\Tasks\SweeperArtefactsTask` is the likely intended state.
- `SweeperClearArchiveTask` contains an unused `deleteArchivedVersions()` method; `flushClass()` calls `deleteArchivedVersionsWithVersionRetention()` instead.
