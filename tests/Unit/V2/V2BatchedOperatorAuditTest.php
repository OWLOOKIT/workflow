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
        for ($i = 0; $i < 12; $i++) {
            $stub = WorkflowStub::make(BatchedAuditFixtureWorkflow::class);
            $stub->start();
            app()
                ->call(
                    [new RunWorkflowTask(WorkflowTask::query()->where(
                        'workflow_run_id',
                        $stub->runId()
                    )->sole()->id), 'handle']
                );
            WorkflowRun::query()->whereKey($stub->runId())->update([
                'namespace' => $i % 2 ? 'school-a' : 'school-b',
            ]);
        }
        $this->assertParity();
        DB::table('workflow_run_timeline_entries')->update([
            'summary' => 'corrupt',
        ]);
        $this->assertParity();
        $this->assertSame(12, SelectedRunProjectionDrift::metrics()['history']['stale_projected_runs']);
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
        // A single chunk must batch failures and updates instead of querying each run.
        $this->assertLessThan(35, count($queries), json_encode(array_count_values($queries)));
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
