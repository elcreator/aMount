<?php

namespace Elcreator\aMount\Services;

use Carbon\Carbon;
use Closure;
use Elcreator\aMount\Drivers\Driver;
use Elcreator\aMount\Drivers\Drivers;
use EvolutionCMS\Models\SystemCliTask;
use EvolutionCMS\Services\SystemTasks\ConsoleInstallFlowService;
use EvolutionCMS\Services\SystemTasks\SystemTaskRegistry;
use EvolutionCMS\Services\SystemTasks\SystemTaskService;
use Illuminate\Support\Str;

/**
 * Installs a missing driver's packages from the manager, through the CMS task queue.
 *
 * Composer never runs in the web request: the request queues an
 * `amount.driver_install` task and `system:task-worker` runs the CMS's own
 * install flow (`ConsoleInstallFlowService`: `package:installrequire` with its
 * rollback, discovery, publishing, migrations). The core's queueing checks run
 * first (`SystemTaskService::createTaskFromStoreRequest()`): its permission,
 * the super-administrator gate, the lock shared with site updates and other
 * installs, the scheduler's health.
 *
 * The request names a driver, never a package: the packages always come from
 * the driver itself, so neither this page nor the CMS's own task endpoint can
 * use the type to install anything else.
 */
final class DriverInstaller
{
    public const TYPE = 'amount.driver_install';

    /** The core permission for installing packages; the type also requires a super administrator. */
    public const PERMISSION = 'system_tasks.manage_packages';

    private const ACTIVE = ['queued', 'picked', 'running'];

    /** @var (Closure(): SystemTaskService)|null test seam: the core service the task is queued through */
    public static ?Closure $service = null;

    public function __construct(private readonly Actor $actor)
    {
    }

    /** Whether this core can queue the install at all (task registry with creators, the console install flow). */
    public static function supported(): bool
    {
        return class_exists(SystemTaskRegistry::class)
            && method_exists(SystemTaskRegistry::class, 'creator')
            && class_exists(SystemTaskService::class)
            && class_exists(ConsoleInstallFlowService::class)
            && SystemTaskRegistry::has(self::TYPE);
    }

    /** Rewriting the site's vendor directory runs code on the server: administrators only. */
    public function allowed(): bool
    {
        return self::supported() && $this->actor->userId() > 0 && $this->actor->principal()->isAdministrator();
    }

    /**
     * Queue the installation of a driver's missing packages.
     *
     * @return array{task: array<string, mixed>, warnings: array<int, array<string, string>>}
     * @throws AmountException
     */
    public function install(string $driverId): array
    {
        if ($this->actor->userId() <= 0 || !$this->actor->principal()->isAdministrator()) {
            throw AmountException::forbidden();
        }

        $driver = Drivers::has($driverId) ? Drivers::get($driverId) : throw AmountException::invalid('error_driver_unknown');
        $name = ['driver' => $driver->label(), 'command' => $driver->installCommand()];

        if ($driver->available()) {
            throw new AmountException('driver_installed', AmountException::text('aMount::global.error_driver_installed', $name), 409);
        }

        if ($driver->missingPackages() === []) {
            throw new AmountException('driver_not_installable', AmountException::text('aMount::global.error_driver_not_installable', $name), 409);
        }

        if (!self::supported()) {
            throw new AmountException('install_unavailable', AmountException::text('aMount::global.error_driver_install_unavailable', $name), 409);
        }

        $pending = self::pendingFor($driver);
        if ($pending !== null) {
            return ['task' => self::present($pending), 'warnings' => []];
        }

        $service = self::$service !== null ? (self::$service)() : new SystemTaskService();
        $result = $service->createTaskFromStoreRequest(self::TYPE, ['driver' => $driver->id()], $this->requester(), true);

        if (empty($result['ok']) || !isset($result['task']['id'])) {
            throw new AmountException('install_refused', AmountException::text('aMount::global.error_driver_install_refused', [
                'reason' => (string) ($result['message'] ?? ''),
            ]), 409);
        }

        return ['task' => $result['task'], 'warnings' => array_values((array) ($result['warnings'] ?? []))];
    }

    /**
     * Where an installation stands, for the page polling it.
     *
     * @return array<string, mixed>
     * @throws AmountException
     */
    public function status(int $taskId): array
    {
        if ($this->actor->userId() <= 0 || !$this->actor->principal()->isAdministrator()) {
            throw AmountException::forbidden();
        }

        $task = self::supported() ? SystemCliTask::query()->where('type', self::TYPE)->find($taskId) : null;

        return $task !== null ? self::present($task) : throw AmountException::notFound('task');
    }

    /**
     * Unfinished installations by driver id, so a reloaded page keeps showing them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function pending(): array
    {
        if (!$this->allowed()) {
            return [];
        }

        $pending = [];
        foreach (self::activeQuery()->orderBy('id')->get() as $task) {
            $presented = self::present($task);
            $pending[$presented['driver']] = $presented;
        }

        return $pending;
    }

    /**
     * The creator the CMS calls once its own checks have passed, from this
     * class or from its generic task endpoint alike.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $requester the requester snapshot the core checked
     * @return array<string, mixed>
     */
    public static function queue(array $request, array $requester = [], bool $isSuperAdmin = false): array
    {
        $id = (string) ($request['driver'] ?? '');
        $driver = Drivers::has($id) ? Drivers::get($id) : null;

        if ($driver === null || $driver->available() || $driver->missingPackages() === []) {
            return ['ok' => false, 'error_code' => 'SNAPSHOT_INVALID', 'message' => 'Not an aMount driver with packages to install.'];
        }

        $packages = $driver->missingPackages();
        $now = Carbon::now();
        $payload = [
            'task_type' => self::TYPE,
            'driver' => $driver->id(),
            'packages' => $packages,
            'display_title' => 'aMount: ' . $driver->label(),
            'package_name' => $packages[0],
            'composer_name' => $packages[0],
            'resolved_version' => '*',
            'composer_version' => '*',
            'source_kind' => 'console',
        ];

        $task = SystemCliTask::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => self::TYPE,
            'target' => $packages[0],
            'requested_version' => '*',
            'status' => 'queued',
            'step' => 'queued',
            'progress' => 0,
            'message' => 'Queued',
            'payload_json' => $payload,
            'result_json' => [],
            'created_by' => isset($requester['user_id']) ? (int) $requester['user_id'] : null,
            'locked_by' => '',
            'attempt_count' => 0,
            'worker_host' => '',
            'worker_pid' => null,
            'error_code' => '',
            'catalog_snapshot_hash' => hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES)),
            'requested_by_snapshot' => $requester,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['ok' => true, 'task' => self::present($task)];
    }

    /** @return array<string, mixed> */
    public static function present(SystemCliTask $task): array
    {
        $payload = is_array($task->payload_json) ? $task->payload_json : [];
        $driver = (string) ($payload['driver'] ?? '');
        $status = (string) $task->status;

        return [
            'id' => (int) $task->getKey(),
            'driver' => $driver,
            'packages' => array_values((array) ($payload['packages'] ?? [])),
            'status' => $status,
            'step' => (string) $task->step,
            'progress' => (int) $task->progress,
            'message' => (string) $task->message,
            'finished' => !in_array($status, self::ACTIVE, true),
            // Asked in this request: a finished install's classes load from the next one on.
            'installed' => $driver !== '' && Drivers::has($driver) && Drivers::get($driver)->available(),
        ];
    }

    private static function pendingFor(Driver $driver): ?SystemCliTask
    {
        foreach (self::activeQuery()->orderByDesc('id')->get() as $task) {
            if ((string) (((array) $task->payload_json)['driver'] ?? '') === $driver->id()) {
                return $task;
            }
        }

        return null;
    }

    /** Unfinished tasks; a picked or running one only while its worker's lease lives, as the core counts them. */
    private static function activeQuery()
    {
        $now = Carbon::now();

        return SystemCliTask::query()
            ->where('type', self::TYPE)
            ->whereIn('status', self::ACTIVE)
            ->where(static function ($query) use ($now) {
                $query->where('status', 'queued')->orWhereNull('lease_expires_at')->orWhere('lease_expires_at', '>=', $now);
            });
    }

    /**
     * The requester snapshot the core checks, from the database rather than the
     * session (`Principal`), like every other permission decision in aMount.
     *
     * @return array<string, mixed>
     */
    private function requester(): array
    {
        $principal = $this->actor->principal();

        return [
            'user_id' => $this->actor->userId(),
            'role' => $principal->role,
            'is_super_admin' => $principal->isAdministrator(),
            'permissions' => [
                'exec_module' => $principal->can('exec_module') ? 1 : 0,
                'system_tasks.view' => $principal->can('system_tasks.view') || $principal->can(self::PERMISSION) ? 1 : 0,
                self::PERMISSION => $principal->can(self::PERMISSION) ? 1 : 0,
            ],
            'requested_at' => date(DATE_ATOM),
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        ];
    }
}
