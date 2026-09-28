<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type('test-external-signal-arguments-workflow')]
#[Signal('pair', [
    [
        'name' => 'first',
        'type' => 'string',
    ],
    [
        'name' => 'second',
        'type' => 'string',
    ],
])]
final class TestExternalSignalArgumentsWorkflow extends Workflow
{
    public function handle(): array
    {
        return [
            'result' => signal('pair'),
        ];
    }
}
