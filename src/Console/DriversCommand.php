<?php

namespace Elcreator\aMount\Console;

use Elcreator\aMount\Drivers\Drivers;
use Illuminate\Console\Command;

/** Lists every driver, installed or not, with the command that installs a missing one. */
class DriversCommand extends Command
{
    protected $signature = 'amount:drivers';

    protected $description = 'List storage drivers and whether their packages are installed';

    public function handle(): int
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
}
