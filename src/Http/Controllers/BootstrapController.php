<?php

namespace Elcreator\aMount\Http\Controllers;

use Elcreator\aMount\Drivers\Drivers;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Queue\Dispatcher;
use Elcreator\aMount\Services\Files;
use Elcreator\aMount\Services\Mounts;
use Elcreator\aMount\Services\Rules;
use Elcreator\aMount\Services\ServerConfig;
use Elcreator\aMount\Support\Config;
use Elcreator\aMount\Support\Lang;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Everything the page needs on load, in one answer.
 *
 * Two things are administrator-only and simply empty for everyone else: the
 * server integration and the list of user groups a site mount can be granted to.
 */
class BootstrapController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->respond(function (): array {
            $actor = $this->actor();
            $mounts = new Mounts($actor);
            $rules = new Rules($actor);
            $manage = $actor->mayManage();
            $principal = $actor->principal();

            return [
                'drivers' => array_values(array_map([$this, 'describeDriver'], Drivers::all())),
                'mounts' => $mounts->visible()->map(fn ($m) => $mounts->present($m))->all(),
                'foreign_mounts' => $mounts->foreign(),
                'rules' => $rules->list()->map(fn ($r) => $rules->present($r))->all(),
                'presets' => $rules->presets(),
                'runs' => $this->activeRuns(),
                'queue' => Dispatcher::status(),
                'server' => $manage ? ServerConfig::status() : null,
                'flags' => [
                    'may_manage' => $manage,
                    'is_admin' => $principal->isAdministrator(),
                    'use_udperms' => $principal->udperms,
                    'serve_enabled' => Config::serveEnabled(),
                ],
                'user_id' => $actor->userId(),
                'groups' => $manage ? $this->userGroups() : [],
                'limits' => [
                    'min_interval' => Rules::MIN_INTERVAL,
                    'per_page' => Files::PER_PAGE,
                    'max_attempts' => (int) Config::limit('max_attempts', 5),
                    'history_days' => (int) Config::limit('history_days', 60),
                    'stale_after' => (int) Config::limit('stale_after', 900),
                ],
                'csrf_token' => $this->csrfToken(),
            ];
        });
    }

    /**
     * A driver for the form: its description plus the texts already translated,
     * so the page does not need a language key per field of every driver.
     *
     * @return array<string, mixed>
     */
    public function describeDriver($driver): array
    {
        $described = $driver->describe();
        $described['description_text'] = Lang::get($described['description']);

        $described['fields'] = array_map(static function (array $field): array {
            $field['label_text'] = Lang::get((string) ($field['label'] ?? $field['name']));
            $field['help_text'] = isset($field['help']) ? Lang::get((string) $field['help']) : null;

            return $field;
        }, $described['fields']);

        return $described;
    }

    /** @return array<int, array<string, mixed>> */
    private function activeRuns(): array
    {
        return $this->runsQuery()->whereIn('status', Run::ACTIVE)->orderByDesc('id')->limit(50)->get()->map(fn (Run $run) => $this->presentRun($run))->all();
    }

    /** @return array<int, array{id:int, name:string}> */
    private function userGroups(): array
    {
        try {
            return DB::table('membergroup_names')->orderBy('name')->get(['id', 'name'])
                ->map(static fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name])->all();
        } catch (Throwable) {
            return [];
        }
    }
}
