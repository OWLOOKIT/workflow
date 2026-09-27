<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\RunTimelineProjector;
use Workflow\V2\Support\RunWaitProjector;

final class V2ProjectionTimestampTimezoneTest extends TestCase
{
    public function testTimelineAndWaitInstantsSurviveDatabaseReloadAcrossTimezones(): void
    {
        $originalAppTimezone = config('app.timezone');
        $originalPhpTimezone = date_default_timezone_get();

        try {
            foreach (['UTC', 'Europe/Kyiv'] as $timezone) {
                config()->set('app.timezone', $timezone);
                date_default_timezone_set($timezone);

                foreach ([
                    'winter' => '2026-01-15T12:00:00.123456Z',
                    'summer' => '2026-07-15T12:00:00.123456Z',
                    'spring_before' => '2026-03-29T00:30:00.123456Z',
                    'spring_after' => '2026-03-29T01:30:00.123456Z',
                    'autumn_first' => '2026-10-25T00:30:00.123456Z',
                    'autumn_second' => '2026-10-25T01:30:00.123456Z',
                ] as $name => $iso) {
                    $run = $this->fixtureRun($iso, $timezone);
                    RunSummaryProjector::project($run->fresh());

                    $event = WorkflowHistoryEvent::query()
                        ->where('workflow_run_id', $run->id)
                        ->orderBy('sequence')
                        ->firstOrFail();
                    $entry = WorkflowTimelineEntry::query()
                        ->where('history_event_id', $event->id)
                        ->firstOrFail();
                    $wait = WorkflowRunWait::query()
                        ->where('workflow_run_id', $run->id)
                        ->firstOrFail();
                    $expectedResolved = Carbon::parse($iso)->addSecond()->toJSON();

                    $this->assertSame($iso, $event->recorded_at?->toJSON(), $name . ' canonical');
                    $this->assertSame($iso, $entry->fresh()->recorded_at?->toJSON(), $name . ' timeline');
                    $this->assertSame($iso, $wait->fresh()->opened_at?->toJSON(), $name . ' opened');
                    $this->assertSame($expectedResolved, $wait->fresh()->resolved_at?->toJSON(), $name . ' resolved');
                    $this->assertSame(
                        Carbon::parse($iso)->utc()->format('Y-m-d H:i:s.u'),
                        DB::table('workflow_run_timeline_entries')->where('id', $entry->id)->value('recorded_at'),
                        $name . ' stored timeline',
                    );
                    $this->assertFalse(RunTimelineProjector::driftStatusForRun($run->fresh())['stale'], $name);
                    $this->assertFalse(RunWaitProjector::driftStatusForRun($run->fresh())['stale'], $name);

                    for ($read = 0; $read < 2; $read++) {
                        $this->assertSame(
                            'workflow_run_timeline_entries',
                            RunTimelineProjector::snapshotForRun($run->fresh())['source'],
                            $name . ' timeline snapshot',
                        );
                        $this->assertSame(
                            'workflow_run_waits',
                            RunWaitProjector::snapshotForRun($run->fresh())['source'],
                            $name . ' wait snapshot',
                        );
                    }

                    $deadline = Carbon::parse($iso)->addMinute()->setTimezone($timezone);
                    $wait->deadline_at = $deadline;
                    $wait->save();
                    $this->assertSame($deadline->toJSON(), $wait->fresh()->deadline_at?->toJSON());
                }
            }
        } finally {
            config()->set('app.timezone', $originalAppTimezone);
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    public function testIncrementalChildResolutionWritesUtcOutsideTheEloquentCast(): void
    {
        $originalAppTimezone = config('app.timezone');
        $originalPhpTimezone = date_default_timezone_get();
        config()
            ->set('app.timezone', 'Europe/Kyiv');
        date_default_timezone_set('Europe/Kyiv');

        try {
            $iso = '2026-10-25T00:30:00.123456Z';
            $run = $this->fixtureRun($iso, 'Europe/Kyiv');
            $event = WorkflowHistoryEvent::create([
                'workflow_run_id' => $run->id,
                'sequence' => 3,
                'event_type' => HistoryEventType::ChildRunCompleted,
                'payload' => [
                    'child_call_id' => 'child-call-1',
                    'child_status' => RunStatus::Completed->value,
                    'child_workflow_type' => 'child.fixture',
                ],
                'recorded_at' => Carbon::parse($iso)->setTimezone('Europe/Kyiv'),
            ]);
            $wait = WorkflowRunWait::create([
                'id' => hash('sha256', $run->id . '|child:child-call-1'),
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'wait_id' => 'child:child-call-1',
                'position' => 0,
                'kind' => 'child',
                'status' => 'open',
            ]);
            $task = new WorkflowTask([
                'id' => (string) Str::ulid(),
                'task_type' => TaskType::Workflow,
                'status' => TaskStatus::Ready,
            ]);

            RunWaitProjector::projectChildResolutionEvent($run, $task, $event->fresh());

            $this->assertSame($iso, $wait->fresh()->resolved_at?->toJSON());
            $this->assertSame(
                '2026-10-25 00:30:00.123456',
                DB::table('workflow_run_waits')->where('id', $wait->id)->value('resolved_at'),
            );
        } finally {
            config()->set('app.timezone', $originalAppTimezone);
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    public function testRebuildRepairsDerivedLocalWallTimeWithoutChangingHistory(): void
    {
        $originalAppTimezone = config('app.timezone');
        $originalPhpTimezone = date_default_timezone_get();
        config()
            ->set('app.timezone', 'Europe/Kyiv');
        date_default_timezone_set('Europe/Kyiv');

        try {
            $run = $this->fixtureRun('2026-01-15T12:00:00.123456Z', 'Europe/Kyiv');
            RunSummaryProjector::project($run->fresh());
            $historyBefore = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('sequence')
                ->get()
                ->map->getAttributes()
                ->all();

            DB::table('workflow_run_timeline_entries')
                ->where('workflow_run_id', $run->id)
                ->update([
                    'recorded_at' => '2026-01-15 14:00:00.123456',
                ]);
            DB::table('workflow_run_waits')
                ->where('workflow_run_id', $run->id)
                ->update([
                    'opened_at' => '2026-01-15 14:00:00.123456',
                ]);

            $this->assertTrue(RunTimelineProjector::driftStatusForRun($run->fresh())['stale']);
            $this->assertTrue(RunWaitProjector::driftStatusForRun($run->fresh())['stale']);
            $this->assertSame(0, Artisan::call('workflow:v2:rebuild-projections', [
                '--run-id' => [$run->id],
                '--needs-rebuild' => true,
                '--json' => true,
            ]));
            $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $first['runs_matched']);
            $this->assertSame(1, $first['run_summaries_rebuilt']);
            $this->assertFalse(RunTimelineProjector::driftStatusForRun($run->fresh())['stale']);
            $this->assertFalse(RunWaitProjector::driftStatusForRun($run->fresh())['stale']);

            $this->assertSame(0, Artisan::call('workflow:v2:rebuild-projections', [
                '--run-id' => [$run->id],
                '--needs-rebuild' => true,
                '--json' => true,
            ]));
            $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, $second['runs_matched']);
            $this->assertSame(
                $historyBefore,
                WorkflowHistoryEvent::query()
                    ->where('workflow_run_id', $run->id)
                    ->orderBy('sequence')
                    ->get()
                    ->map->getAttributes()
                    ->all(),
            );
        } finally {
            config()->set('app.timezone', $originalAppTimezone);
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    private function fixtureRun(string $iso, string $timezone): WorkflowRun
    {
        $instance = WorkflowInstance::create([
            'id' => 'projection-timezone-' . Str::uuid(),
            'workflow_class' => 'ProjectionTimezoneFixture',
            'workflow_type' => 'projection.timezone.fixture',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'ProjectionTimezoneFixture',
            'workflow_type' => 'projection.timezone.fixture',
            'status' => RunStatus::Completed,
        ]);
        $instance->update([
            'current_run_id' => $run->id,
        ]);

        foreach ([
            [HistoryEventType::SignalWaitOpened, [
                'signal_name' => 'continue',
                'signal_wait_id' => 'signal-wait-1',
                'sequence' => 1,
            ]],
            [HistoryEventType::SignalReceived, [
                'signal_name' => 'continue',
                'signal_wait_id' => 'signal-wait-1',
                'signal_id' => 'signal-1',
            ]],
        ] as $index => [$type, $payload]) {
            WorkflowHistoryEvent::create([
                'workflow_run_id' => $run->id,
                'sequence' => $index + 1,
                'event_type' => $type,
                'payload' => $payload,
                'recorded_at' => Carbon::parse($iso)->addSeconds($index)->setTimezone($timezone),
            ]);
        }

        return $run;
    }
}
