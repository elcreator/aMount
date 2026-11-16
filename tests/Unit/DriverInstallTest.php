<?php

use Elcreator\aMount\Console\DriverInstallHandler;
use Elcreator\aMount\Console\DriversCommand;
use Elcreator\aMount\Drivers\Driver;
use Elcreator\aMount\Drivers\Drivers;
use Elcreator\aMount\Http\Controllers\BootstrapController;
use Elcreator\aMount\Http\Controllers\DriverController;
use Elcreator\aMount\Services\Actor;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\DriverInstaller;
use Elcreator\aMount\aMountServiceProvider;
use EvolutionCMS\Models\SystemCliTask;
use EvolutionCMS\Services\SystemTasks\SystemTaskRegistry;
use EvolutionCMS\Services\SystemTasks\SystemTaskService;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use League\Flysystem\FilesystemAdapter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    amountReset();
    SystemTaskRegistry::forget(DriverInstaller::TYPE);
    (new aMountServiceProvider(Container::getInstance()))->register();
    DriverInstaller::$service = null;
    DriverInstallHandler::$flow = null;
});

afterEach(function () {
    DriverInstaller::$service = null;
    DriverInstallHandler::$flow = null;
});

/** A driver whose package Composer has not installed (or, with an installed one, that still cannot start). */
function amountUninstalledDriver(string $id = 'acme', array $packages = ['acme/flysystem-missing']): Driver
{
    $driver = new class extends Driver {
        public string $driverId = 'acme';

        /** @var string[] */
        public array $driverPackages = [];

        public function id(): string
        {
            return $this->driverId;
        }

        public function label(): string
        {
            return 'Acme storage';
        }

        public function fields(): array
        {
            return [];
        }

        public function packages(): array
        {
            return $this->driverPackages;
        }

        protected function probeClass(): string
        {
            return 'Acme\\Flysystem\\MissingAdapter';
        }

        protected function adapter(array $config, array $options): FilesystemAdapter
        {
            throw new RuntimeException('Not installed.');
        }
    };
    $driver->driverId = $id;
    $driver->driverPackages = $packages;
    Drivers::register($driver);

    return $driver;
}

/** The core's task service with its environment checks (scheduler health, lock) answered by the test. */
function amountTaskService(array $preflight = ['ok' => true, 'warnings' => []]): void
{
    DriverInstaller::$service = static fn () => new class($preflight) extends SystemTaskService {
        public function __construct(private array $answer)
        {
            parent::__construct();
        }

        protected function runCreatePreflight($type, array $requesterSnapshot = [], $isSuperAdmin = false)
        {
            // The parts of the real preflight that need no running scheduler.
            if (empty($requesterSnapshot['permissions']['exec_module'])) {
                return ['ok' => false, 'error_code' => 'ACL_DENIED', 'message' => 'No exec_module.'];
            }
            foreach (SystemTaskRegistry::permissions($type) as $permission) {
                if (empty($requesterSnapshot['permissions'][$permission])) {
                    return ['ok' => false, 'error_code' => 'ACL_DENIED', 'message' => 'Missing ' . $permission . '.'];
                }
            }
            if (SystemTaskRegistry::requiresSuperAdmin($type) && !$isSuperAdmin) {
                return ['ok' => false, 'error_code' => 'ACL_DENIED', 'message' => 'Super administrators only.'];
            }

            return $this->answer;
        }
    };
}

function amountInstallRefusal(callable $call): ?AmountException
{
    try {
        $call();
    } catch (AmountException $e) {
        return $e;
    }

    return null;
}

it('registers the install task type as exclusive, for super administrators with the package permission', function () {
    $definition = SystemTaskRegistry::definition(DriverInstaller::TYPE);

    expect(SystemTaskRegistry::isExclusive(DriverInstaller::TYPE))->toBeTrue()
        ->and(SystemTaskRegistry::permissions(DriverInstaller::TYPE))->toBe(['system_tasks.manage_packages'])
        ->and(SystemTaskRegistry::requiresSuperAdmin(DriverInstaller::TYPE))->toBeTrue()
        ->and(SystemTaskRegistry::creator(DriverInstaller::TYPE))->toBe([DriverInstaller::class, 'queue'])
        ->and($definition['handler'])->toBe(DriverInstallHandler::class)
        ->and(DriverInstaller::supported())->toBeTrue();

    (new aMountServiceProvider(Container::getInstance()))->register();
    expect(SystemTaskRegistry::definition(DriverInstaller::TYPE))->toBe($definition);
});

it('tells which packages of a driver Composer has not installed', function () {
    expect(amountUninstalledDriver()->missingPackages())->toBe(['acme/flysystem-missing'])
        ->and(amountUninstalledDriver('half', ['league/flysystem', 'acme/other'])->missingPackages())->toBe(['acme/other'])
        ->and(Drivers::get('local')->missingPackages())->toBe([]);
});

it('offers the install to administrators only', function () {
    amountAdmin(1);
    amountUser(7, ['amount', 'amount_manage', 'system_tasks.manage_packages', 'exec_module']);
    amountUninstalledDriver();

    expect((new DriverInstaller(Actor::for(1)))->allowed())->toBeTrue()
        ->and((new DriverInstaller(Actor::for(7)))->allowed())->toBeFalse()
        ->and((new DriverInstaller(Actor::for(0)))->allowed())->toBeFalse();

    $refused = amountInstallRefusal(fn () => (new DriverInstaller(Actor::for(7)))->install('acme'));

    expect($refused?->error)->toBe('forbidden')->and($refused?->status)->toBe(403)
        ->and(SystemCliTask::query()->count())->toBe(0);
});

it('queues the driver\'s own packages through the core\'s task service', function () {
    amountAdmin(1);
    amountUninstalledDriver();
    amountTaskService(['ok' => true, 'warnings' => [['code' => 'WORKER_STATUS_WARNING', 'message' => 'Worker idle.']]]);

    $result = (new DriverInstaller(Actor::for(1)))->install('acme');
    $task = SystemCliTask::query()->firstOrFail();

    expect($task->type)->toBe('amount.driver_install')
        ->and($task->status)->toBe('queued')
        ->and($task->target)->toBe('acme/flysystem-missing')
        ->and($task->created_by)->toBe(1)
        ->and($task->payload_json['driver'])->toBe('acme')
        ->and($task->payload_json['packages'])->toBe(['acme/flysystem-missing'])
        ->and($task->requested_by_snapshot['permissions']['system_tasks.manage_packages'])->toBe(1)
        ->and($result['task']['id'])->toBe($task->id)
        ->and($result['task']['finished'])->toBeFalse()
        ->and($result['task']['installed'])->toBeFalse()
        ->and($result['warnings'][0]['message'])->toBe('Worker idle.');
});

it('answers a second request with the installation already under way', function () {
    amountAdmin(1);
    amountUninstalledDriver();
    amountTaskService();
    $installer = new DriverInstaller(Actor::for(1));

    $first = $installer->install('acme');
    $second = $installer->install('acme');

    expect($second['task']['id'])->toBe($first['task']['id'])
        ->and(SystemCliTask::query()->count())->toBe(1)
        ->and($installer->pending())->toHaveKey('acme');
});

it('reports the core\'s refusal', function () {
    amountAdmin(1);
    amountUninstalledDriver();
    amountTaskService(['ok' => false, 'error_code' => 'GLOBAL_LOCK_ACTIVE', 'message' => 'Another system task is already queued or running.']);

    $refused = amountInstallRefusal(fn () => (new DriverInstaller(Actor::for(1)))->install('acme'));

    expect($refused?->error)->toBe('install_refused')->and($refused?->status)->toBe(409)
        ->and($refused?->getMessage())->toContain('Another system task is already queued or running.')
        ->and(SystemCliTask::query()->count())->toBe(0);
});

it('waits its turn behind another install, by the core\'s own lock', function () {
    amountAdmin(1);
    amountUninstalledDriver();
    // The real service: a queued core install holds the lock every exclusive task shares.
    SystemCliTask::query()->create(['uuid' => 'core-1', 'type' => 'console_install', 'status' => 'queued', 'payload_json' => [], 'result_json' => []]);

    $refused = amountInstallRefusal(fn () => (new DriverInstaller(Actor::for(1)))->install('acme'));

    expect($refused?->error)->toBe('install_refused')
        ->and($refused?->getMessage())->toContain('Another system task is already queued or running.')
        ->and(SystemCliTask::query()->where('type', DriverInstaller::TYPE)->count())->toBe(0);
});

it('refuses what there is nothing to install for', function () {
    amountAdmin(1);
    amountUninstalledDriver('half', ['league/flysystem']);
    amountTaskService();
    $installer = new DriverInstaller(Actor::for(1));

    expect(amountInstallRefusal(fn () => $installer->install('local'))?->error)->toBe('driver_installed')
        ->and(amountInstallRefusal(fn () => $installer->install('half'))?->error)->toBe('driver_not_installable')
        ->and(amountInstallRefusal(fn () => $installer->install('nope'))?->error)->toBe('error_driver_unknown')
        ->and(SystemCliTask::query()->count())->toBe(0);
});

it('lets the core\'s generic endpoint queue only a missing driver\'s own packages', function () {
    amountUninstalledDriver();

    expect(DriverInstaller::queue(['driver' => 'local'])['ok'])->toBeFalse()
        ->and(DriverInstaller::queue(['driver' => 'nope', 'packages' => ['evil/package']])['ok'])->toBeFalse()
        ->and(SystemCliTask::query()->count())->toBe(0);

    $queued = DriverInstaller::queue(['driver' => 'acme', 'packages' => ['evil/package'], 'composer_name' => 'evil/package'], ['user_id' => 1]);

    expect($queued['ok'])->toBeTrue()
        ->and(SystemCliTask::query()->firstOrFail()->payload_json['packages'])->toBe(['acme/flysystem-missing']);
});

it('installs each package of the driver with the core\'s console install flow', function () {
    amountUninstalledDriver('acme', ['acme/one', 'acme/two']);
    $seen = new ArrayObject();
    DriverInstallHandler::$flow = static fn () => new class($seen) {
        public function __construct(private ArrayObject $seen)
        {
        }

        public function execute(SystemCliTask $task, ?callable $report = null): array
        {
            $this->seen[] = [$task->payload_json['composer_name'], $task->payload_json['composer_version']];

            return ['message' => 'ok', 'result' => []];
        }
    };

    $task = new SystemCliTask();
    // A row naming another package installs the driver's own ones all the same.
    $task->forceFill(['type' => DriverInstaller::TYPE, 'payload_json' => ['driver' => 'acme', 'packages' => ['evil/package'], 'composer_name' => 'evil/package']]);

    $result = (new DriverInstallHandler())->execute($task);

    expect($seen->getArrayCopy())->toBe([['acme/one', '*'], ['acme/two', '*']])
        ->and($result['result']['packages'])->toBe(['acme/one', 'acme/two']);

    $task->forceFill(['payload_json' => ['driver' => 'nope']]);
    expect(fn () => (new DriverInstallHandler())->execute($task))->toThrow(RuntimeException::class);
});

it('reports an installation to the administrator polling it', function () {
    amountAdmin(1);
    amountUser(7);
    amountUninstalledDriver();
    amountTaskService();
    $id = (new DriverInstaller(Actor::for(1)))->install('acme')['task']['id'];
    SystemCliTask::query()->whereKey($id)->update(['status' => 'failed', 'message' => 'composer update failed']);
    $other = SystemCliTask::query()->create(['uuid' => 'x-1', 'type' => 'amount.sync', 'status' => 'queued', 'payload_json' => [], 'result_json' => []]);

    $status = (new DriverInstaller(Actor::for(1)))->status($id);

    expect($status['finished'])->toBeTrue()->and($status['status'])->toBe('failed')
        ->and($status['message'])->toBe('composer update failed')
        ->and(amountInstallRefusal(fn () => (new DriverInstaller(Actor::for(1)))->status($other->id))?->status)->toBe(404)
        ->and(amountInstallRefusal(fn () => (new DriverInstaller(Actor::for(7)))->status($id))?->status)->toBe(403);
});

it('answers the page through the controller and the bootstrap flags', function () {
    amountAdmin(1);
    amountUser(7);
    amountUninstalledDriver();
    amountTaskService();

    AmountTestCore::$loginUserId = 7;
    $denied = (new DriverController())->install(Request::create('/x', 'POST', ['driver' => 'acme']));
    $flags = (new BootstrapController())->show()->getData(true);

    expect($denied->getStatusCode())->toBe(403)
        ->and($flags['flags']['may_install_drivers'])->toBeFalse()
        ->and($flags['driver_installs'])->toBe([]);

    AmountTestCore::$loginUserId = 1;
    $queued = (new DriverController())->install(Request::create('/x', 'POST', ['driver' => 'acme']))->getData(true);
    $polled = (new DriverController())->status($queued['task']['id'])->getData(true);
    $boot = (new BootstrapController())->show()->getData(true);

    expect($queued['ok'])->toBeTrue()
        ->and($polled['task']['status'])->toBe('queued')
        ->and($boot['flags']['may_install_drivers'])->toBeTrue()
        ->and($boot['driver_installs']['acme']['id'])->toBe($queued['task']['id']);
});

/** Runs `amount:drivers` with the given input; `package:installrequire` is recorded, not run. */
function amountDriversCommand(array $input, int $exitCode = 0): array
{
    $command = new class extends DriversCommand {
        /** @var string[] */
        public array $installed = [];

        public int $exitCode = 0;

        protected function installPackage(string $package): int
        {
            $this->installed[] = $package;

            return $this->exitCode;
        }
    };
    $command->exitCode = $exitCode;
    $output = new BufferedOutput();
    $arguments = new ArrayInput($input, $command->getDefinition());
    $command->setInput($arguments);
    $command->setOutput(new OutputStyle($arguments, $output));
    $status = $command->handle();

    return [$status, $command->installed, $output->fetch()];
}

it('installs drivers from artisan, one Composer run per missing package', function () {
    amountUninstalledDriver('acme', ['acme/one']);
    amountUninstalledDriver('acme2', ['acme/one', 'acme/two']);

    [$status, $installed, $output] = amountDriversCommand(['--install' => ['acme,local', 'acme2']]);

    expect($status)->toBe(0)
        ->and($installed)->toBe(['acme/one', 'acme/two'])
        ->and($output)->toContain('is already installed');
});

it('stops at the first failed package and at an unknown driver', function () {
    amountUninstalledDriver('acme', ['acme/one', 'acme/two']);

    [$status, $installed] = amountDriversCommand(['--install' => ['acme']], 1);
    expect($status)->toBe(1)->and($installed)->toBe(['acme/one']);

    [$status, $installed, $output] = amountDriversCommand(['--install' => ['nope']]);
    expect($status)->toBe(1)->and($installed)->toBe([])->and($output)->toContain('Unknown driver: nope');
});

it('still lists the drivers without --install', function () {
    amountUninstalledDriver();

    [$status, $installed, $output] = amountDriversCommand([]);

    expect($status)->toBe(0)->and($installed)->toBe([])
        ->and($output)->toContain('acme')->toContain('amount:drivers --install=acme');
});
