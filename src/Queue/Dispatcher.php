<?php

namespace Elcreator\aMount\Queue;

use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Support\Config;
use Elcreator\aMount\Support\Lang;
use RuntimeException;

/**
 * Chooses the queue that carries a run.
 *
 * `auto` prefers sTask when it is installed and usable, because it comes with
 * its own manager page and scheduling; otherwise the CMS's system task queue,
 * which is always there. `system` and `stask` force one.
 */
final class Dispatcher
{
    /** @throws RuntimeException when the forced queue is not usable */
    public static function driver(): Queue
    {
        $system = new SystemQueue();
        $stask = new STaskQueue();

        switch (Config::queueDriver()) {
            case 'system':
                return $system;
            case 'stask':
                if (!$stask->available()) {
                    throw new RuntimeException('The queue driver is set to sTask, but sTask is not installed.');
                }

                return $stask;
            default:
                return $stask->available() ? $stask : $system;
        }
    }

    public static function dispatch(Run $run): void
    {
        static::driver()->dispatch($run);
    }

    /**
     * What the page shows about the queue: the active driver, both queues'
     * health and the commands that keep them moving.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $system = new SystemQueue();
        $stask = new STaskQueue();

        try {
            $active = static::driver()->name();
            $error = null;
        } catch (RuntimeException $e) {
            $active = null;
            $error = $e->getMessage();
        }

        $hints = [];

        if ($active === 'system' || $active === null) {
            $hints[] = ['queue' => 'system', 'message' => Lang::get('aMount::global.queue_hint_system'), 'command' => 'php core/artisan system:task-worker'];
        }

        if ($active === 'stask') {
            $hints[] = ['queue' => 'stask', 'message' => Lang::get('aMount::global.queue_hint_stask'), 'command' => 'php core/artisan stask:worker'];
        }

        $hints[] = ['queue' => 'cron', 'message' => Lang::get('aMount::global.queue_hint_cron'), 'command' => '* * * * * cd /path/to/core && php artisan schedule:run >> /dev/null 2>&1'];

        return [
            'driver' => $active,
            'configured' => Config::queueDriver(),
            'error' => $error,
            'queues' => [
                'system' => ['available' => $system->available()] + $system->health(),
                'stask' => ['available' => $stask->available()] + $stask->health(),
            ],
            'hints' => $hints,
        ];
    }
}
