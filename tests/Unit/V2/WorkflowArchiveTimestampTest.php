<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

final class WorkflowArchiveTimestampTest extends TestCase
{
    public function testArchiveInstantSurvivesHydrationAndProjectionAcrossTimezones(): void
    {
        $originalTimezone = date_default_timezone_get();
        $instants = [
            '2026-01-15T11:59:00.123456Z',
            '2026-07-15T11:59:00.123456Z',
            '2026-03-29T00:59:59.123456Z',
            '2026-03-29T01:00:01.123456Z',
            '2026-10-25T00:59:59.123456Z',
            '2026-10-25T01:00:01.123456Z',
        ];

        try {
            foreach (['UTC', 'Europe/Kyiv'] as $timezone) {
                date_default_timezone_set($timezone);
                foreach ($instants as $instant) {
                    $expected = Carbon::parse($instant, 'UTC');
                    Carbon::setTestNow($expected);
                    $raw = $expected->format('Y-m-d H:i:s.u');
                    foreach ([WorkflowRun::class, WorkflowRunSummary::class] as $modelClass) {
                        $model = new $modelClass();
                        $model->setAppends([]);
                        $model->setRawAttributes([
                            'archived_at' => $raw,
                        ], true);
                        $this->assertSame(
                            $expected->format('U.u'),
                            $model->archived_at->format('U.u'),
                            $timezone . ' ' . $modelClass
                        );
                        $this->assertSame($raw, $model->getRawOriginal('archived_at'));
                        $this->assertSame($expected->toISOString(), $model->attributesToArray()['archived_at']);

                        $local = $expected->copy()
                            ->setTimezone($timezone);
                        $model->archived_at = $local;
                        $this->assertSame($raw, $model->getAttributes()['archived_at']);
                        $this->assertSame($timezone, $local->getTimezone()->getName());
                        $model->archived_at = null;
                        $this->assertNull($model->archived_at);
                    }
                }
            }
        } finally {
            Carbon::setTestNow();
            date_default_timezone_set($originalTimezone);
        }
    }
}
