<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.browse')]
#[Description('Read-only. List the remote sub-folders (at most 500, by name) of a folder on a mount, e.g. to answer "what is in my Google Drive folder X" or to pick a destination. Paths are relative to the mount as the manager sees it. Returns {path, folders:[{name, path}]}; pass a folder\'s `path` back as `path` to go deeper. Files are listed with amount.files (offloaded ones) or fetched with amount.fetch.')]
class BrowseTool extends AmountTool
{
    protected function run(Delivery $delivery, array $args): array
    {
        return Operations::browse($delivery, $args);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mount' => $schema->string()->required()->description('Mount uuid from amount.mounts'),
            'path' => $schema->string()->description('Remote folder to list, e.g. "Pictures/Cats" (default: the top)'),
        ];
    }
}
