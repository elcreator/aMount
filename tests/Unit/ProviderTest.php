<?php

use Elcreator\aMount\Console\SyncHandler;
use Elcreator\aMount\Listeners\Cleanup;
use Elcreator\aMount\Listeners\NotFound;
use Elcreator\aMount\aMountServiceProvider;
use EvolutionCMS\Services\SystemTasks\SystemTaskRegistry;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;

beforeEach(function () {
    amountReset();
    SystemTaskRegistry::forget(SyncHandler::TYPE);
});

function amountProvider(?Container $app = null): aMountServiceProvider
{
    return new aMountServiceProvider($app ?? Container::getInstance());
}

function amountCall(object $object, string $method, array $args = [])
{
    $reflection = new ReflectionMethod($object, $method);

    return $reflection->invoke($object, ...$args);
}

it('registers the transfer task type as concurrent with the configured parallelism', function () {
    expect(SystemTaskRegistry::has(SyncHandler::TYPE))->toBeFalse();

    amountProvider()->register();

    $definition = SystemTaskRegistry::definition(SyncHandler::TYPE);

    expect(SystemTaskRegistry::has(SyncHandler::TYPE))->toBeTrue()
        ->and(SystemTaskRegistry::mode(SyncHandler::TYPE))->toBe(SystemTaskRegistry::MODE_CONCURRENT)
        ->and(SystemTaskRegistry::isExclusive(SyncHandler::TYPE))->toBeFalse()
        ->and(SystemTaskRegistry::parallelism(SyncHandler::TYPE))->toBe(2)
        ->and(SystemTaskRegistry::permissions(SyncHandler::TYPE))->toBe(['amount'])
        ->and(SystemTaskRegistry::label(SyncHandler::TYPE))->toBe('aMount transfer')
        ->and($definition['handler'] ?? SyncHandler::class)->toBe(SyncHandler::class);
});

it('does not register the task type twice', function () {
    amountProvider()->register();
    $first = SystemTaskRegistry::definition(SyncHandler::TYPE);

    amountProvider()->register();

    expect(SystemTaskRegistry::definition(SyncHandler::TYPE))->toBe($first);
});

it('merges its configuration under cms.settings.aMount, keeping what the site overrode', function () {
    config(['cms.settings.aMount' => ['limits' => ['parallelism' => 4]]]);

    amountProvider()->register();

    expect(config('cms.settings.aMount.limits.parallelism'))->toBe(4)
        ->and(config('cms.settings.aMount.queue.driver'))->toBe('auto')
        ->and(config('cms.settings.aMount.serve.enabled'))->toBeTrue()
        ->and(SystemTaskRegistry::parallelism(SyncHandler::TYPE))->toBe(4);
});

it('listens to the CMS events it needs', function () {
    $app = new Container();
    $app->instance('events', $events = new Dispatcher($app));

    amountCall(amountProvider($app), 'registerEvents');

    expect($events->hasListeners('evolution.OnPageNotFound'))->toBeTrue()
        ->and($events->hasListeners('evolution.OnManagerDeleteUser'))->toBeTrue()
        ->and(method_exists(NotFound::class, 'handle'))->toBeTrue()
        ->and(method_exists(Cleanup::class, 'user'))->toBeTrue();
});

it('does not answer page-not-found when serving is switched off', function () {
    config(['cms.settings.aMount.serve.enabled' => false]);

    $app = new Container();
    $app->instance('events', $events = new Dispatcher($app));

    amountCall(amountProvider($app), 'registerEvents');

    expect($events->hasListeners('evolution.OnPageNotFound'))->toBeFalse()
        ->and($events->hasListeners('evolution.OnManagerDeleteUser'))->toBeTrue();
});

it('schedules amount:tick every minute, once', function () {
    // The Schedule builds its mutex from the global container, so that is the one to use.
    $app = Container::getInstance();
    $app->instance(EventMutex::class, new class implements EventMutex {
        public function create(Event $event): bool
        {
            return true;
        }

        public function exists(Event $event): bool
        {
            return false;
        }

        public function forget(Event $event): void
        {
        }
    });
    $app->instance(SchedulingMutex::class, new class implements SchedulingMutex {
        public function create(Event $event, \DateTimeInterface $time): bool
        {
            return true;
        }

        public function exists(Event $event, \DateTimeInterface $time): bool
        {
            return false;
        }
    });
    $app->singleton(Schedule::class, fn () => new Schedule('UTC'));

    amountCall(amountProvider($app), 'scheduleTick');

    $app->make(Schedule::class);
    $schedule = $app->make(Schedule::class);

    $events = array_values(array_filter($schedule->events(), fn ($e) => str_contains((string) $e->command, 'amount:tick')));

    $app->forgetInstance(EventMutex::class);
    $app->forgetInstance(SchedulingMutex::class);
    $app->forgetInstance(Schedule::class);

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('* * * * *');
});

it('uses a Font Awesome icon the manager ships', function () {
    $css = 'C:/projects/Opensource/evo/evolution/manager/media/style/common/font-awesome/css/font-awesome.min.css';

    if (!is_file($css)) {
        $this->markTestSkipped('The CMS checkout is not next to this package.');
    }

    $class = substr(aMountServiceProvider::MODULE_ICON, strlen('fa '));

    expect(file_get_contents($css))->toContain('.' . $class);
});
