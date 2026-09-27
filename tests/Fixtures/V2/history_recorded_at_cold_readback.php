<?php

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\HistoryRecordedAt;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$required = static function (string $name): string {
    $value = getenv($name);
    if (! is_string($value) || $value === '') {
        throw new RuntimeException(sprintf('Missing history cold-readback field [%s].', $name));
    }

    return $value;
};

$driver = $required('HISTORY_DB_DRIVER');
$database = $required('HISTORY_DB_DATABASE');
$dsn = match ($driver) {
    'sqlite' => 'sqlite:' . $database,
    'pgsql', 'mysql' => sprintf(
        '%s:host=%s;port=%s;dbname=%s',
        $driver,
        $required('HISTORY_DB_HOST'),
        $required('HISTORY_DB_PORT'),
        $database,
    ),
    default => throw new RuntimeException(sprintf('Unsupported history database driver [%s].', $driver)),
};

date_default_timezone_set($required('HISTORY_APP_TIMEZONE'));
$pdo = new PDO(
    $dsn,
    (string) getenv('HISTORY_DB_USERNAME'),
    (string) getenv('HISTORY_DB_PASSWORD'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ],
);
if ($driver === 'mysql') {
    $pdo->exec("SET time_zone = '+00:00'");
}

$query = $pdo->prepare('SELECT recorded_at, recorded_at_utc FROM workflow_history_events WHERE id = :id');
$query->execute([
    'id' => $required('HISTORY_EVENT_ID'),
]);
$attributes = $query->fetch(PDO::FETCH_ASSOC);
if (! is_array($attributes)) {
    throw new RuntimeException('The persisted history event was not found.');
}

$instant = (new HistoryRecordedAt())->get(
    new WorkflowHistoryEvent(),
    'recorded_at',
    $attributes['recorded_at'],
    $attributes,
);

echo json_encode([
    'recorded_at' => $attributes['recorded_at'],
    'recorded_at_utc' => $attributes['recorded_at_utc'],
    'instant' => $instant?->toJSON(),
], JSON_THROW_ON_ERROR);
