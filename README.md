# Silverstripe Sweeper

A set of tasks that help clean up long-running silverstripe projects.

## Tasks

### sweeper-artefacts

Builds a clean in-memory database and compares it with the schema of the currently configured database,
will then run a diff of both schemas to discern any extraneous tables or columns that can be removed.

NOTE: This means that anything that is stored in the database that is not defined in the silverstripe schema
WILL be removed.

### sweeper-archive

Prunes backlog of version history to a fixed number per record, as well as
any versions for archived or orphaned records. Note that this module will make
deleted objects unrecoverable.
Run with ?run=yes to acknowledge that deleted pages cannot be recovered,
and that you have made a backup manually, or run with ?run=dry to dry-run.

(Optional) Set keep=<num> (default: 10) to specify number of versions to keep.

NOTE: If running with the snapshots cleanup enabled, it is most likely necessary to temporarily increase the `max_prepared_stmt_count` on a database level.


### sweeper-report

Runs the following checks and reports their results, this could be used
  during refactoring or code-cleanup to discern where to look.

  1. Looks for defined data objects that have no active instances.
      - Arguments:
          1. no-silverstripe-filter (any value): Report will no longer filter out classes in the Silverstripe// namespace.
          2. namespace-filter (string): Report will filter out classes in the given namespace, note that this is anything that CONTAINS the given namespace, not starts with.
  2. Looks for DataExtensions that are defined but never applied.
