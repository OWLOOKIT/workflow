# Timeline and wait projection timestamps

The `workflow_run_timeline_entries.recorded_at` column and
`workflow_run_waits.opened_at`, `deadline_at`, and `resolved_at` columns store
UTC wall times with microsecond precision. They are timezone-free database
columns; model hydration interprets them as UTC. The incremental child
resolution update also writes UTC explicitly because query updates bypass
Eloquent casts. The history event and other durable runtime rows remain the
source of truth for projections.

Previously, the default datetime cast could write a UTC value and read it back
as local time. In non-UTC applications this shifted operator-facing timestamps
and made `--needs-rebuild` repeatedly select the same run. Old projection rows
may contain either UTC or local wall times, depending on the write path. Do not
bulk-convert them by assuming one timezone.

After upgrading all projection writers, back up the workflow database and run
`workflow:v2:rebuild-projections --needs-rebuild --dry-run --json` to inspect
the affected run count. Then run the same command without `--dry-run`, or use
`--run-id` to repair selected runs in batches. Recheck drift and run the dry
run again; an unchanged source should select no runs. Verify timeline and wait
views show the intended instants and that operator projection warnings clear.
The rebuild changes derived rows only. It cannot recover an offset already
lost from authoritative history; investigate such rows under the history
timestamp procedure before interpreting a rebuilt projection as corrected.
