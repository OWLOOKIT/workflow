<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\SelectedRunProjectionDrift;
use Workflow\V2\WorkflowStub;

final class V2BatchedOperatorAuditTest extends TestCase
{
    public function testBatchAuditMatchesCanonicalChecksAndDetectsCorruption(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('workflows.v2.connection', 'redis');
        Queue::fake();
        for ($i = 0; $i < 112; $i++) {
            $stub = WorkflowStub::make(BatchedAuditFixtureWorkflow::class);
            $stub->start();
            $task = WorkflowTask::query()->where('workflow_run_id', $stub->runId())->sole();
            app()
                ->call([new RunWorkflowTask($task->id), 'handle']);
            WorkflowRun::query()->whereKey($stub->runId())->update([
                'namespace' => $i % 2 ? 'school-a' : 'school-b',
            ]);
        }
        $this->assertParity();
        DB::table('workflow_run_timeline_entries')->update([
            'summary' => 'corrupt',
        ]);
        $this->assertParity();
        $this->assertSame(112, SelectedRunProjectionDrift::metrics()['history']['stale_projected_runs']);
        $run = WorkflowRun::query()->firstOrFail();
        DB::table('workflow_run_timeline_entries')->where('workflow_run_id', $run->id)->delete();
        DB::table('workflow_run_waits')->where('workflow_run_id', $run->id)->delete();
        $this->assertParity();
        $metrics = SelectedRunProjectionDrift::metrics();
        $this->assertGreaterThan(0, $metrics['history']['missing_runs_with_history']);
        $this->assertGreaterThan(0, $metrics['waits']['missing_runs_with_waits']);

        $queries = [];
        DB::listen(static function () use (&$queries): void {
            $queries[] = func_get_arg(0)->sql;
        });
        SelectedRunProjectionDrift::metrics();
        // Two chunks must batch failures and updates instead of querying each run.
        $this->assertLessThan(70, count($queries), json_encode(array_count_values($queries)));
    }

    public function testCollectionReuseIsScopedAndClearedAfterFailure(): void
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        try {
            \Workflow\V2\Support\OperatorMetrics::collectOnce(function () use (&$queries): void {
                $first = \Workflow\V2\Support\OperatorMetrics::snapshot(namespace: 'a');
                $before = $queries;
                $this->assertSame($first, \Workflow\V2\Support\OperatorMetrics::snapshot(namespace: 'a'));
                $this->assertSame($before, $queries);
                $this->assertSame($first, \Workflow\V2\Support\OperatorMetrics::collectOnce(
                    static fn (): array => \Workflow\V2\Support\OperatorMetrics::snapshot(namespace: 'a')
                ));
                $this->assertSame($before, $queries);
                \Workflow\V2\Support\OperatorMetrics::snapshot(namespace: 'b');
                $this->assertGreaterThan($before, $queries);
                throw new \RuntimeException('collection failed');
            });
            $this->fail('Expected collection failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('collection failed', $exception->getMessage());
        }
        $before = $queries;
        \Workflow\V2\Support\OperatorMetrics::snapshot(namespace: 'a');
        $this->assertGreaterThan($before, $queries);
    }

    public function testExplicitTimeIsPreservedAndDefaultHealthSharesCollection(): void
    {
        $reference = \Carbon\CarbonImmutable::parse('2026-01-02T03:04:05Z');
        \Workflow\V2\Support\OperatorMetrics::collectOnce(function () use ($reference): void {
            $first = \Workflow\V2\Support\OperatorMetrics::snapshot(namespace: 'empty');
            $health = \Workflow\V2\Support\HealthCheck::snapshot(namespace: 'empty');
            $this->assertSame($first, $health['operator_metrics']);
            $explicit = \Workflow\V2\Support\OperatorMetrics::snapshot($reference, 'empty');
            $this->assertSame($reference->toJSON(), $explicit['generated_at']);
            $later = \Workflow\V2\Support\HealthCheck::snapshot($reference->addHour(), 'empty');
            $this->assertSame($reference->addHour()->toJSON(), $later['generated_at']);
            $queries = 0;
            DB::listen(static function () use (&$queries): void {
                $queries++;
            });
            $this->assertSame($explicit, \Workflow\V2\Support\OperatorMetrics::snapshot($reference, 'empty'));
            $this->assertSame($first, \Workflow\V2\Support\OperatorMetrics::snapshot(namespace: 'empty'));
            $this->assertSame(0, $queries);
        });
    }

    public function testDashboardAndHealthReuseOneAuditWithExplicitTimePreserved(): void
    {
        \Workflow\V2\Support\OperatorMetrics::collectOnce(function (): void {
            $dashboard = \Workflow\V2\Support\OperatorDashboardSummary::snapshot(namespace: 'empty');
            $queries = 0;
            DB::listen(static function () use (&$queries): void {
                $queries++;
            });
            $health = \Workflow\V2\Support\HealthCheck::snapshot(namespace: 'empty');
            $this->assertSame($dashboard['operator_metrics'], $health['operator_metrics']);
            $this->assertSame(0, $queries);

            $reference = \Carbon\CarbonImmutable::parse('2026-01-02T03:04:05Z');
            $explicit = \Workflow\V2\Support\OperatorDashboardSummary::snapshot($reference, 'empty');
            $this->assertSame($reference->toJSON(), $explicit['operator_metrics']['generated_at']);
            $this->assertCount(169, $explicit['fleet_trends_series']['timestamps']);
            $this->assertSame(
                $reference->copy()
                    ->subWeek()
                    ->startOfHour()
->timestamp * 1000,
                $explicit['fleet_trends_series']['timestamps'][0]
            );
            $this->assertSame(
                $reference->copy()
                    ->startOfHour()
->timestamp * 1000,
                $explicit['fleet_trends_series']['timestamps'][168]
            );
            $before = $queries;
            $this->assertSame(
                $explicit['operator_metrics'],
                \Workflow\V2\Support\HealthCheck::snapshot($reference, 'empty')['operator_metrics']
            );
            $this->assertSame($before, $queries);
        });
    }

    public function testDefaultDashboardPreservesCollectionTimezone(): void
    {
        $timezone = date_default_timezone_get();
        $reference = \Carbon\CarbonImmutable::parse('2026-01-02 03:04:05', 'Asia/Kolkata');
        date_default_timezone_set('Asia/Kolkata');
        $this->travelTo($reference);
        try {
            \Workflow\V2\Support\OperatorMetrics::collectOnce(function () use ($reference): void {
                $dashboard = \Workflow\V2\Support\OperatorDashboardSummary::snapshot(namespace: 'empty');
                $this->assertSame(
                    $reference->subWeek()
                        ->startOfHour()
->timestamp * 1000,
                    $dashboard['fleet_trends_series']['timestamps'][0]
                );
                $this->assertSame(
                    $reference->startOfHour()
->timestamp * 1000,
                    $dashboard['fleet_trends_series']['timestamps'][168]
                );
            });
        } finally {
            $this->travelBack();
            date_default_timezone_set($timezone);
        }
    }

    public function testCommandPayloadHandlesPartiallyLoadedRun(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('workflows.v2.connection', 'redis');
        Queue::fake();
        $stub = WorkflowStub::make(BatchedAuditFixtureWorkflow::class);
        $stub->start();
        WorkflowRun::query()->whereKey($stub->runId())->update([
            'namespace' => 'school-a',
        ]);
        $command = new \Workflow\V2\Models\WorkflowCommand([
            'workflow_run_id' => $stub->runId(),
            'payload_codec' => 'avro',
            'payload' => \Workflow\Serializers\Serializer::serializeWithCodec('avro', [
                'arguments' => [42],
            ]),
        ]);
        $command->setRelation('run', WorkflowRun::query()->select('id')->findOrFail($stub->runId()));
        $strict = \Illuminate\Database\Eloquent\Model::preventsAccessingMissingAttributes();
        \Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes();
        try {
            $queries = 0;
            DB::listen(static function () use (&$queries): void {
                $queries++;
            });
            $this->assertSame([42], $command->payloadArguments());
            $this->assertSame(1, $queries);
            $command->setRelation('run', WorkflowRun::query()->findOrFail($stub->runId()));
            $before = $queries;
            $this->assertSame([42], $command->payloadArguments());
            $this->assertSame($before, $queries);
        } finally {
            \Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes($strict);
        }
    }

    private function assertParity(): void
    {
        foreach ([null, 'school-a', 'school-b', 'empty'] as $namespace) {
            $this->assertSame([
                'waits' => SelectedRunProjectionDrift::waitMetrics(namespace: $namespace),
                'history' => SelectedRunProjectionDrift::timelineMetrics(namespace: $namespace),
                'timers' => SelectedRunProjectionDrift::timerMetrics(namespace: $namespace),
                'lineage' => SelectedRunProjectionDrift::lineageMetrics(namespace: $namespace),
            ], SelectedRunProjectionDrift::metrics($namespace));
        }
    }
}

final class BatchedAuditFixtureWorkflow extends \Workflow\V2\Workflow
{
    public function handle(): int
    {
        return \Workflow\V2\localActivity(BatchedAuditFixtureActivity::class);
    }
}

final class BatchedAuditFixtureActivity extends \Workflow\V2\Activity
{
    public function handle(): int
    {
        return 42;
    }
}
