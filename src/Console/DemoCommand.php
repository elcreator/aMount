<?php

namespace Elcreator\aMount\Console;

use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\Actor;
use Elcreator\aMount\Services\Mounts;
use Elcreator\aMount\Services\Rules;
use Elcreator\aMount\Services\Syncer;
use Elcreator\aMount\Support\Paths;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Seeds a self-contained demo: a local-disk "cloud", sample files, one public
 * and one backup-like private rule, and (with --s3) the same on a MinIO bucket
 * taken from the demo stack's environment. Safe to run repeatedly.
 */
class DemoCommand extends Command
{
    protected $signature = 'amount:demo {--user= : Administrator user id (default: the first one)} {--s3 : Also seed an S3/MinIO mount from AMOUNT_DEMO_S3_* variables} {--run : Run the rules now, synchronously} {--evict : With --run, delete the local copy of the demo images so they are served from the store}';

    protected $description = 'Seed demo mounts, files and rules';

    private const FOLDER = 'assets/amount-demo';

    public function handle(): int
    {
        $userId = (int) ($this->option('user') ?: DB::table('user_attributes')->where('role', 1)->orderBy('internalKey')->value('internalKey'));

        if ($userId <= 0) {
            $this->error('No administrator account found. Pass --user=<id>.');

            return self::FAILURE;
        }

        $actor = Actor::for($userId);

        if (!$actor->mayManage()) {
            $this->error('User ' . $userId . ' may not administer aMount.');

            return self::FAILURE;
        }

        $this->samples();

        $mounts = new Mounts($actor);
        $rules = new Rules($actor);
        $seeded = [];

        $cloud = $this->mount($mounts, 'Demo cloud', 'local', ['root' => $this->cloudRoot()], ['serve' => 'proxy']);
        $seeded[] = $this->rule($rules, $cloud, 'Demo: backups', self::FOLDER . '/backups', 'demo/backups', 'private');
        $seeded[] = $this->rule($rules, $cloud, 'Demo: images', self::FOLDER . '/images', 'demo/images', 'public');
        $this->info('Demo cloud ready (' . $cloud->uuid . ').');

        if ($this->option('s3')) {
            array_push($seeded, ...$this->s3($mounts, $rules));
        }

        if ($this->option('run')) {
            $this->runAll($seeded);
        }

        return self::SUCCESS;
    }

    /** @return Rule[] */
    private function s3(Mounts $mounts, Rules $rules): array
    {
        $env = [];

        foreach (['ENDPOINT', 'BUCKET', 'KEY', 'SECRET', 'REGION'] as $name) {
            $env[$name] = (string) getenv('AMOUNT_DEMO_S3_' . $name);
        }

        if ($env['ENDPOINT'] === '' || $env['BUCKET'] === '' || $env['KEY'] === '' || $env['SECRET'] === '') {
            $this->warn('Skipping --s3: set AMOUNT_DEMO_S3_ENDPOINT, _BUCKET, _KEY and _SECRET (the demo Docker stack does).');

            return [];
        }

        $mount = $this->mount($mounts, 'Demo S3 (MinIO)', 's3', [
            'bucket' => $env['BUCKET'], 'region' => $env['REGION'] ?: 'us-east-1', 'endpoint' => $env['ENDPOINT'],
            'path_style' => true, 'key' => $env['KEY'], 'secret' => $env['SECRET'],
        ], ['serve' => 'proxy']);

        $this->info('Demo S3 mount ready (' . $mount->uuid . ').');

        return [
            $this->rule($rules, $mount, 'Demo S3: backups', self::FOLDER . '/backups', 'demo-backups', 'private'),
            $this->rule($rules, $mount, 'Demo S3: images', self::FOLDER . '/images', 'demo-images', 'public'),
        ];
    }

    private function mount(Mounts $mounts, string $name, string $driver, array $config, array $options): Mount
    {
        $existing = Mount::query()->where('name', $name)->whereNull('owner_id')->where('driver', $driver)->first();

        $mount = $existing !== null
            ? $mounts->update($existing, ['config' => $config, 'options' => $options])
            : $mounts->create(['name' => $name, 'driver' => $driver, 'scope' => 'site', 'config' => $config, 'options' => $options]);

        $result = $mounts->test($mount);
        $this->line(($result['ok'] ? '  [ok] ' : '  [fail] ') . $name . ($result['ok'] ? '' : ': ' . $result['message']));

        return $mount;
    }

    private function rule(Rules $rules, Mount $mount, string $name, string $path, string $prefix, string $visibility): Rule
    {
        $input = [
            'mount' => $mount->uuid, 'name' => $name, 'local_path' => $path, 'remote_prefix' => $prefix,
            'visibility' => $visibility, 'min_age' => 0, 'mode' => 'copy', 'retention_days' => $visibility === 'private' ? 30 : 0,
        ];
        $existing = Rule::query()->where('mount_id', $mount->getKey())->where('local_path', $path)->where('ephemeral', false)->first();

        return $existing !== null ? $rules->update($existing, $input) : $rules->create($input);
    }

    /** Sample files, written only when missing. */
    private function samples(): void
    {
        $files = [
            self::FOLDER . '/images/pixel.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
            self::FOLDER . '/images/notes.txt' => "aMount demo: this file is offloaded to the demo cloud and served from there.\n",
            self::FOLDER . '/backups/demo-backup-' . date('Ymd') . '.sql' => "-- demo dump\nCREATE TABLE demo (id INT);\n",
        ];

        foreach ($files as $path => $content) {
            $absolute = Paths::absolute($path);

            if (!is_file($absolute)) {
                @mkdir(dirname($absolute), 0775, true);
                file_put_contents($absolute, $content);
            }
        }
    }

    private function cloudRoot(): string
    {
        $base = defined('EVO_STORAGE_PATH') ? EVO_STORAGE_PATH : sys_get_temp_dir();
        $root = rtrim(str_replace('\\', '/', $base), '/') . '/amount-demo-cloud';
        @mkdir($root, 0775, true);

        return $root;
    }

    /** @param Rule[] $rules */
    private function runAll(array $rules): void
    {
        foreach ($rules as $rule) {
            try {
                $run = Run::query()->create([
                    'rule_id' => $rule->getKey(), 'started_by' => $rule->owner_id, 'direction' => 'up',
                    'status' => Run::QUEUED, 'heartbeat_at' => Carbon::now(),
                ]);
                (new Syncer($run))->work(null);
                $run->refresh();
                $this->line(sprintf('  %s: %s (%d/%d files)%s', $rule->name, $run->status, $run->files_done, $run->files_total, $run->message ? ' - ' . $run->message : ''));
            } catch (Throwable $e) {
                $this->error('  ' . $rule->name . ': ' . $e->getMessage());
            }
        }

        if ($this->option('evict')) {
            $entries = FileEntry::query()->where('visibility', 'public')->where('status', FileEntry::UPLOADED)
                ->where('local_path', 'like', self::FOLDER . '/images/%')->get();

            foreach ($entries as $entry) {
                @unlink(Paths::absolute($entry->local_path));
                $entry->update(['local_removed' => true]);
                $this->line('  Local copy removed; still served at /' . $entry->local_path);
            }
        }
    }
}
