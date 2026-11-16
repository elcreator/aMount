<?php

namespace Elcreator\aMount\Console;

use Closure;
use Elcreator\aMount\Drivers\Drivers;
use Elcreator\aMount\Services\DriverInstaller;
use EvolutionCMS\Interfaces\SystemTaskHandlerInterface;
use EvolutionCMS\Models\SystemCliTask;
use EvolutionCMS\Services\SystemTasks\ConsoleInstallFlowService;
use RuntimeException;

/**
 * Runs an `amount.driver_install` task: the CMS's own console install flow,
 * once per package the driver lists.
 *
 * The packages are read from the driver again, not from the task row, so a row
 * written by anything else still installs nothing but a driver's own packages.
 */
class DriverInstallHandler implements SystemTaskHandlerInterface
{
    /** @var (Closure(): object)|null test seam: what `execute(SystemCliTask, ?callable)` is delegated to */
    public static ?Closure $flow = null;

    /**
     * @param callable|null $report step/progress/message reporter from the worker
     * @return array{message: string, result: array}
     */
    public function execute(SystemCliTask $task, ?callable $report = null)
    {
        $payload = is_array($task->payload_json) ? $task->payload_json : [];
        $id = (string) ($payload['driver'] ?? '');

        if (!Drivers::has($id)) {
            throw new RuntimeException('Unknown aMount driver "' . $id . '".');
        }

        $driver = Drivers::get($id);
        $packages = $driver->packages();

        foreach ($packages as $package) {
            $step = clone $task;
            $step->forceFill(['payload_json' => [
                'composer_name' => $package,
                'resolved_version' => '*',
                'composer_version' => '*',
                'source_kind' => 'console',
            ] + $payload]);

            $this->flow()->execute($step, $report);
        }

        return [
            'message' => $driver->label() . ' installed.',
            'result' => ['task_type' => DriverInstaller::TYPE, 'driver' => $id, 'packages' => $packages],
        ];
    }

    private function flow(): object
    {
        return self::$flow !== null ? (self::$flow)() : new ConsoleInstallFlowService();
    }
}
