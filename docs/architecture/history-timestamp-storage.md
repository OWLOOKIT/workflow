# Workflow history timestamp storage

`workflow_history_events.recorded_at` was originally an offset-free database
timestamp interpreted in the PHP process timezone. That representation cannot
distinguish the two occurrences of a repeated local hour. The history event
sequence remains the durable ordering key, but replay and operator views also
consume the absolute `recorded_at` instant.

New writers store two values for each event:

- `recorded_at_utc` is a timezone-free UTC wall time with microsecond precision.
  New readers use it as the authoritative instant.
- `recorded_at` retains the PHP writer's local wall time for older readers.
  It is a compatibility field, not evidence of an unambiguous instant.

The new column is nullable. The migration adds it without rewriting existing
history. When `recorded_at_utc` is absent, new readers preserve the old cast's
interpretation of `recorded_at` in the PHP process timezone. This keeps old
rows readable but cannot recover an offset that was never stored. Neither
projection rebuild nor a database timezone change can recover that offset.
The migration also adds the column to an existing configured history-event
table. Applications that create or replace that table in their own migrations
must include the same nullable UTC column before starting new writers.

## Rollout and recovery

Back up the workflow database, apply migrations, then roll out the new package.
During a rolling upgrade, older workers read the local compatibility field from
new events; new workers read UTC for new events and the legacy fallback for
events written by older workers. Keep the PHP timezone consistent across old
workers and stop old writers before relying on repeated-hour correctness. A
mixed fleet containing old writers can still create ambiguous rows during a
clock fallback. Verify the fleet has upgraded before that boundary. For MySQL
`TIMESTAMP` compatibility fields, keep the database session timezone at UTC so
the database does not add another conversion.

For existing rows, use independent audit or application evidence to identify
the intended instant before setting `recorded_at_utc`. A repeated-hour local
value alone is insufficient. Back up and review any proposed correction row by
row; do not bulk-fill `recorded_at_utc` by assuming an offset. Keep history
sequence, payload, and execution state unchanged. Rebuild derived projections
only after the source instant is established, then verify replay and operator
views for the affected run.
