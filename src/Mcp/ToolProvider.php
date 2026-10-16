<?php

namespace Elcreator\aMount\Mcp;

use Elcreator\aMount\Mcp\Tools\BrowseTool;
use Elcreator\aMount\Mcp\Tools\DeliverTool;
use Elcreator\aMount\Mcp\Tools\FetchTool;
use Elcreator\aMount\Mcp\Tools\MountsTool;
use Elcreator\aMount\Mcp\Tools\StatusTool;
use EvolutionCMS\eMCP\Contracts\ToolProvider as Contract;

/**
 * Offers mounts to AI agents through eMCP (evolution-cms/emcp), a soft
 * dependency: the provider registers this class only when eMCP exists, so
 * nothing here is autoloaded on a site without it.
 *
 * Every tool is one call on {@see \Elcreator\aMount\Services\Delivery} for the
 * token owner, so an agent gets exactly what that manager gets on the page.
 */
final class ToolProvider implements Contract
{
    public function server(): string
    {
        return (string) config('cms.settings.aMount.mcp.server', 'content');
    }

    public function tools(): array
    {
        return [MountsTool::class, BrowseTool::class, DeliverTool::class, StatusTool::class, FetchTool::class];
    }
}
