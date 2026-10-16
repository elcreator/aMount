<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.browse')]
#[Description('List the remote sub-folders of a mount, to choose a destination folder.')]
class BrowseTool extends AmountTool
{
    protected function run(Delivery $delivery, Request $request): array
    {
        return $delivery->browse($this->requireString($request, 'mount'), (string) $request->get('path', ''));
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mount' => $schema->string()->required()->description('Mount uuid from amount.mounts'),
            'path' => $schema->string()->description('Remote folder to list (default: the top)'),
        ];
    }
}
