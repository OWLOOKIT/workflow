<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\ConfiguredV2Models;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        $historyModel = ConfiguredV2Models::resolve('history_event_model', WorkflowHistoryEvent::class);
        $tables = array_unique(['workflow_history_events', (new $historyModel())->getTable()]);
        $schema = Schema::connection($this->getConnection());

        foreach ($tables as $tableName) {
            if (! $schema->hasTable($tableName) || $schema->hasColumn($tableName, 'recorded_at_utc')) {
                continue;
            }

            $schema->table($tableName, static function (Blueprint $table): void {
                $table->dateTime('recorded_at_utc', 6)
                    ->nullable();
            });
        }
    }

    public function down(): void
    {
        // Dropping this column would discard the only unambiguous timestamp
        // for events written during a repeated local hour.
    }
};
