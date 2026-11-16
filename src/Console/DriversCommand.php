<?php

namespace Elcreator\aMount\Console;

use Elcreator\aMount\Drivers\Drivers;
use Illuminate\Console\Command;

/**
 * Lists every driver, installed or not, with the command that installs a missing one;
 * `--install` installs missing drivers through the CMS's `package:installrequire`.
 */
class DriversCommand extends Command
{
    protected $signature = 'amount:drivers
        {--install=* : Install these drivers\' packages (driver ids, repeated or comma-separated, e.g. s3,sftp,webdav)}';

    protected $description = 'List storage drivers and whether their packages are installed, or install them';

    public function handle(): int
    {
        $wanted = $this->wanted();

        return $wanted === [] ? $this->list() : $this->install($wanted);
    }

    private function list(): int
    {
        $rows = [];

        foreach (Drivers::all() as $driver) {
            $rows[] = [
                $driver->id(),
                $driver->label(),
                $driver->available() ? 'installed' : 'missing',
                $driver->available() ? '' : $driver->installCommand(),
            ];
        }

        $this->table(['Id', 'Driver', 'Status', 'Install'], $rows);

        return self::SUCCESS;
    }

    /** @param string[] $ids */
    private function install(array $ids): int
    {
        $unknown = array_values(array_filter($ids, static fn (string $id): bool => !Drivers::has($id)));
        if ($unknown !== []) {
            $this->error('Unknown driver: ' . implode(', ', $unknown) . '. Run amount:drivers for the list.');

            return self::FAILURE;
        }

        $packages = [];
        foreach ($ids as $id) {
            $driver = Drivers::get($id);
            if ($driver->available()) {
                $this->line($driver->label() . ' is already installed.');
                continue;
            }
            if ($driver->missingPackages() === []) {
                $this->warn($driver->label() . ' has its packages but still cannot start: check the PHP extensions it needs.');
                continue;
            }
            array_push($packages, ...$driver->missingPackages());
        }

        // One Composer run per package: package:installrequire rolls back only its own
        // package, so packages recorded without a run would stay half-added after a failure.
        foreach (array_values(array_unique($packages)) as $package) {
            $this->info('Installing ' . $package . '...');
            $exitCode = $this->installPackage($package);

            if ($exitCode !== 0) {
                $this->error('Installing ' . $package . ' failed; the site keeps its previous packages.');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** @return string[] driver ids from every --install value */
    private function wanted(): array
    {
        $ids = [];
        foreach ((array) $this->option('install') as $value) {
            foreach (explode(',', (string) $value) as $id) {
                if (trim($id) !== '') {
                    $ids[] = trim($id);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    protected function installPackage(string $package): int
    {
        return (int) $this->call('package:installrequire', ['key' => $package, 'value' => '*']);
    }
}
