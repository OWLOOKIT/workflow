<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

final class V2HistoryRecordedAtTimezoneTest extends TestCase
{
    public function testExplicitHistoryInstantsSurvivePersistenceAndColdReload(): void
    {
        $originalAppTimezone = config('app.timezone');
        $originalPhpTimezone = date_default_timezone_get();
        config()
            ->set('app.timezone', 'Europe/Kyiv');
        date_default_timezone_set('Europe/Kyiv');

        try {
            $run = $this->fixtureRun();
            $cases = [
                'winter' => '2026-01-15T12:00:00.123456Z',
                'summer' => '2026-07-15T12:00:00.123456Z',
                'spring_before' => '2026-03-29T00:30:00.123456Z',
                'spring_after' => '2026-03-29T01:30:00.123456Z',
                'autumn_first' => '2026-10-25T00:30:00.123456Z',
                'autumn_second' => '2026-10-25T01:30:00.123456Z',
            ];
            $reloaded = [];

            foreach ($cases as $name => $iso) {
                $instant = Carbon::parse($iso)->setTimezone('Europe/Kyiv');
                $event = WorkflowHistoryEvent::create([
                    'workflow_run_id' => $run->id,
                    'sequence' => count($reloaded) + 1,
                    'event_type' => HistoryEventType::WorkflowStarted,
                    'payload' => [],
                    'recorded_at' => $instant,
                ]);
                $fresh = $event->fresh();
                $this->assertSame($iso, $fresh->recorded_at?->toJSON(), $name);
                $this->assertSame($iso, $this->coldReadback($event)['instant'], $name . ' cold');
                $reloaded[$name] = $fresh->recorded_at?->getTimestamp();
            }

            $this->assertSame(3600, $reloaded['autumn_second'] - $reloaded['autumn_first']);
            $this->assertSame(
                $this->coldReadback(WorkflowHistoryEvent::where('sequence', 5)->firstOrFail())['recorded_at'],
                $this->coldReadback(WorkflowHistoryEvent::where('sequence', 6)->firstOrFail())['recorded_at'],
                'Older readers retain the same local wall-clock representation for the repeated hour.',
            );
        } finally {
            config()->set('app.timezone', $originalAppTimezone);
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    public function testRecordUsesTheSuppliedClockInstant(): void
    {
        $originalAppTimezone = config('app.timezone');
        $originalPhpTimezone = date_default_timezone_get();
        config()
            ->set('app.timezone', 'Europe/Kyiv');
        date_default_timezone_set('Europe/Kyiv');

        try {
            $run = $this->fixtureRun();
            // Carbon's named-zone test clock can shift the first autumn fold.
            // Explicit persistence above covers that fold; this checks record()
            // only after verifying the clock's actual supplied instant.
            $instant = Carbon::parse('2026-07-15T12:00:00.654321Z');
            Carbon::setTestNow($instant);
            $this->assertSame($instant->toJSON(), now()->toJSON(), 'The test clock must supply the intended instant.');

            $event = WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted);
            $this->assertSame($instant->toJSON(), $event->fresh()->recorded_at?->toJSON());
            $this->assertSame($instant->toJSON(), $this->coldReadback($event)['instant']);
        } finally {
            Carbon::setTestNow();
            config()
                ->set('app.timezone', $originalAppTimezone);
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    public function testExistingHistoryWithoutUtcValueKeepsLegacyHydration(): void
    {
        $originalPhpTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Kyiv');

        try {
            $run = $this->fixtureRun();
            $event = WorkflowHistoryEvent::create([
                'workflow_run_id' => $run->id,
                'sequence' => 1,
                'event_type' => HistoryEventType::WorkflowStarted,
                'payload' => [],
                'recorded_at' => Carbon::parse('2026-10-25T00:30:00Z'),
            ]);
            DB::table('workflow_history_events')->where('id', $event->id)->update([
                'recorded_at_utc' => null,
            ]);

            $raw = $this->coldReadback($event);
            $this->assertNull($raw['recorded_at_utc']);
            $this->assertSame('2026-10-25T01:30:00.000000Z', $raw['instant']);
            $this->assertSame($raw['instant'], $event->fresh()->recorded_at?->toJSON());
        } finally {
            date_default_timezone_set($originalPhpTimezone);
        }
    }

    public function testMalformedRecordedAtCannotCreateHistoryEvent(): void
    {
        $run = $this->fixtureRun();
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count();

        try {
            WorkflowHistoryEvent::create([
                'workflow_run_id' => $run->id,
                'sequence' => 1,
                'event_type' => HistoryEventType::WorkflowStarted,
                'payload' => [],
                'recorded_at' => [],
            ]);
        } catch (InvalidArgumentException) {
            $this->assertSame($before, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());

            return;
        }

        $this->fail('A malformed timestamp must not create a history event.');
    }

    private function fixtureRun(): WorkflowRun
    {
        $instance = WorkflowInstance::create([
            'id' => 'history-timezone-' . Str::uuid(),
            'workflow_class' => 'HistoryTimezoneFixture',
            'workflow_type' => 'history.timezone.fixture',
            'run_count' => 1,
        ]);

        return WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'HistoryTimezoneFixture',
            'workflow_type' => 'history.timezone.fixture',
            'status' => RunStatus::Completed,
        ]);
    }

    /**
     * @return array{recorded_at: string, recorded_at_utc: string|null, instant: string|null}
     */
    private function coldReadback(WorkflowHistoryEvent $event): array
    {
        $database = $event->getConnection()
            ->getConfig();
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/../../Fixtures/V2/history_recorded_at_cold_readback.php',
        ], env: [
            'HISTORY_DB_DRIVER' => (string) ($database['driver'] ?? ''),
            'HISTORY_DB_DATABASE' => (string) ($database['database'] ?? ''),
            'HISTORY_DB_HOST' => (string) ($database['host'] ?? ''),
            'HISTORY_DB_PORT' => (string) ($database['port'] ?? ''),
            'HISTORY_DB_USERNAME' => (string) ($database['username'] ?? ''),
            'HISTORY_DB_PASSWORD' => (string) ($database['password'] ?? ''),
            'HISTORY_APP_TIMEZONE' => date_default_timezone_get(),
            'HISTORY_EVENT_ID' => $event->id,
        ]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
