<?php

namespace Elcreator\aMount;

use Elcreator\aMount\Console\DemoCommand;
use Elcreator\aMount\Console\DriversCommand;
use Elcreator\aMount\Console\ServerCommand;
use Elcreator\aMount\Console\SyncHandler;
use Elcreator\aMount\Console\TickCommand;
use Elcreator\aMount\Listeners\Cleanup;
use Elcreator\aMount\Listeners\NotFound;
use Elcreator\aMount\Queue\STaskQueue;
use Elcreator\aMount\Services\Mounts;
use Elcreator\aMount\Support\Config;
use EvolutionCMS\ServiceProvider;
use EvolutionCMS\Services\SystemTasks\SystemTaskRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

class aMountServiceProvider extends ServiceProvider
{
    /** The manager menu entry, and the name the route prefix is derived from. */
    public const MODULE_NAME = 'aMount';

    /** Font Awesome icon of the menu entry (the manager ships FA 5 free: `fa-cloud-upload-alt`). */
    public const MODULE_ICON = 'fa fa-cloud-upload-alt';

    public function register(): void
    {
        // Merged in `register()` rather than `boot()`: the task registration
        // below reads it, and a worker resolves handlers before any boot
        // method of ours has run.
        $this->mergeConfigFrom(dirname(__DIR__) . '/config/aMount.php', 'cms.settings.aMount');

        $this->registerTaskType();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');
        $this->loadTranslationsFrom(dirname(__DIR__) . '/lang', 'aMount');
        $this->loadViewsFrom(dirname(__DIR__) . '/views', 'aMount');

        $this->ensureTranslationFallback();

        if (!Config::isEnabled()) {
            return;
        }

        $this->commands([
            TickCommand::class,
            ServerCommand::class,
            DriversCommand::class,
            DemoCommand::class,
        ]);

        $this->scheduleTick();
        $this->registerEvents();

        if ($this->inBackend()) {
            $this->syncConfigMounts();
            STaskQueue::ensureRegistered();
        }

        $this->registerMcpTools();
        $this->registerManagerModule();
    }

    /**
     * Declare `amount.sync` to the CMS task queue.
     *
     * Registered unconditionally, and in `register()`, because the consumer is
     * `system:task-worker` - a CLI process with no manager session, no module
     * menu and no routes. Gated on being in the backend, queued transfers would
     * be picked up and failed with TASK_TYPE_NOT_ALLOWED.
     *
     * `concurrent`: a transfer does not rewrite the installation, so it must
     * not block site updates the way the exclusive core task types do.
     */
    protected function registerTaskType(): void
    {
        if (!class_exists(SystemTaskRegistry::class)) {
            // An older core without the task registry: the page says so
            // instead of queueing work nothing would run.
            return;
        }

        if (SystemTaskRegistry::has(SyncHandler::TYPE)) {
            return;
        }

        SystemTaskRegistry::register(SyncHandler::TYPE, SyncHandler::class, [
            'mode' => SystemTaskRegistry::MODE_CONCURRENT,
            'parallelism' => max(1, (int) Config::limit('parallelism', 2)),
            'permissions' => ['amount'],
            'label' => 'aMount transfer',
        ]);
    }

    /** `amount:tick` every minute, through the Laravel scheduler the CMS cron line drives. */
    protected function scheduleTick(): void
    {
        $this->app->afterResolving(Schedule::class, static function (Schedule $schedule) {
            foreach ($schedule->events() as $event) {
                if ((string) ($event->description ?? '') === 'amount:tick') {
                    return;
                }
            }

            $schedule->command('amount:tick')->everyMinute()->withoutOverlapping()->name('amount:tick');
        });
    }

    protected function registerEvents(): void
    {
        $events = $this->app['events'];

        if (Config::serveEnabled()) {
            $events->listen('evolution.OnPageNotFound', [NotFound::class, 'handle']);
        }

        $events->listen('evolution.OnManagerDeleteUser', [Cleanup::class, 'user']);
    }

    /** Mirror configuration-defined mounts into rows; only where the tables exist. */
    protected function syncConfigMounts(): void
    {
        try {
            if (Schema::hasTable('amount_mounts') && Schema::hasTable('amount_rules')) {
                Mounts::syncConfigMounts();
            }
        } catch (\Throwable) {
            // Migrations not run yet: nothing to mirror.
        }
    }

    /** Console and manager requests only: never a cost on the front end. */
    protected function inBackend(): bool
    {
        return $this->app->runningInConsole() || (defined('IN_MANAGER_MODE') && IN_MANAGER_MODE === true);
    }

    /**
     * Offer mounts to AI agents when eMCP (evolution-cms/emcp) is installed.
     * A soft dependency: the tool classes extend Laravel MCP's Tool, which
     * only exists alongside eMCP.
     */
    protected function registerMcpTools(): void
    {
        if (!(bool) Config::get('mcp.enabled', true)) {
            return;
        }

        $registry = 'EvolutionCMS\eMCP\Services\ToolRegistry';

        if (!interface_exists('EvolutionCMS\eMCP\Contracts\ToolProvider') || !class_exists($registry)) {
            return;
        }

        $this->app->make($registry)->register(new Mcp\ToolProvider());
    }

    /**
     * The manager menu entry and the routes behind it. `registerRoutingModule()`
     * refuses outside the backend, so the routes exist only where a manager
     * session can reach them.
     */
    protected function registerManagerModule(): void
    {
        $this->app->registerRoutingModule(self::MODULE_NAME, __DIR__ . '/Http/routes.php', self::MODULE_ICON);
    }

    /**
     * Give the translator a fallback when the CMS has not set one, so a
     * manager language this package ships no file for renders English instead
     * of raw keys.
     */
    protected function ensureTranslationFallback(): void
    {
        try {
            $translator = $this->app['translator'];
        } catch (\Throwable) {
            return;
        }

        if (!method_exists($translator, 'getFallback') || !method_exists($translator, 'setFallback')) {
            return;
        }

        if ((string) $translator->getFallback() !== '') {
            return;
        }

        $fallback = trim((string) config('app.fallback_locale', 'en'));

        $translator->setFallback($fallback !== '' ? $fallback : 'en');
    }
}
