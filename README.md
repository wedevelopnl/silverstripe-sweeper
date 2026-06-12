# Silverstripe Sweeper

A set of tasks that help clean up long-running silverstripe projects.

## Tasks

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
