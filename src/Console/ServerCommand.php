<?php

namespace Elcreator\aMount\Console;

use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\ServerConfig;
use Illuminate\Console\Command;

/**
 * Shows, writes and probes the web-server integration that lets offloaded
 * public files keep their URLs (see {@see ServerConfig}).
 */
class ServerCommand extends Command
{
    protected $signature = 'amount:server {server? : nginx, apache or frankenphp (default: detected)} {--write : Write the include file / .htaccess block} {--probe : Check that the web server forwards missing files to the CMS}';

    protected $description = 'Web-server setup for offloaded public files';

    public function handle(): int
    {
        $server = (string) ($this->argument('server') ?: ServerConfig::detect());
        $dirs = ServerConfig::directories();

        $this->line('Server: ' . $server);
        $this->line('Public folders: ' . ($dirs === [] ? '(none)' : implode(', ', $dirs)));

        try {
            if (!in_array($server, ServerConfig::SERVERS, true)) {
                $this->warn('Unknown web server. Pass one of: ' . implode(', ', ServerConfig::SERVERS));

                return self::FAILURE;
            }

            $this->newLine();
            $this->line(rtrim(ServerConfig::render($server, $dirs)) ?: '(nothing to add)');

            if ($this->option('write')) {
                $result = ServerConfig::write($server);
                $this->info(($result['changed'] ? 'Written: ' : 'Already up to date: ') . $result['path']);
            }

            if ($this->option('probe')) {
                foreach ($dirs ?: ['assets'] as $dir) {
                    $probe = ServerConfig::probe($dir);
                    $this->line(($probe['ok'] ? '[ok]   ' : '[fail] ') . $probe['url'] . ' - ' . $probe['message']);
                }
            }
        } catch (AmountException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
