<?php

namespace Elcreator\aMount\Mcp;

use Elcreator\aMount\Mcp\Tools\BrowseTool;
use Elcreator\aMount\Mcp\Tools\CancelTool;
use Elcreator\aMount\Mcp\Tools\DeleteRemoteTool;
use Elcreator\aMount\Mcp\Tools\DeliverTool;
use Elcreator\aMount\Mcp\Tools\FetchTool;
use Elcreator\aMount\Mcp\Tools\FilesTool;
use Elcreator\aMount\Mcp\Tools\MeasureTool;
use Elcreator\aMount\Mcp\Tools\MountsTool;
use Elcreator\aMount\Mcp\Tools\RecallTool;
use Elcreator\aMount\Mcp\Tools\RulesTool;
use Elcreator\aMount\Mcp\Tools\ComputersTool;
use Elcreator\aMount\Mcp\Tools\RunsTool;
use Elcreator\aMount\Mcp\Tools\StatusTool;
use EvolutionCMS\eMCP\Contracts\ToolProvider as Contract;

/**
 * Offers mounts to AI agents through eMCP (evolution-cms/emcp), a soft
 * dependency: the provider registers this class only when eMCP exists, so
 * nothing here is autoloaded on a site without it.
 *
 * Every tool is one call on {@see \Elcreator\aMount\Services\Delivery} for the
 * token owner, so an agent gets exactly what that manager gets on the page.
 * The tools that change anything implement eMCP's WritesSite marker; the
 * read-only ones (mounts, browse, status, runs, rules, files, measure) need
 * only the read/call scopes.
 */
final class ToolProvider implements Contract
{
    public function server(): string
    {
        return (string) config('cms.settings.aMount.mcp.server', 'content');
    }

    public function tools(): array
    {
        return [
            MountsTool::class, BrowseTool::class, RulesTool::class, FilesTool::class, RunsTool::class, StatusTool::class,
            MeasureTool::class, DeliverTool::class, FetchTool::class, RecallTool::class, CancelTool::class, DeleteRemoteTool::class,
            ComputersTool::class,
        ];
    }
}
