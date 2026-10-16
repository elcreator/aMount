<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.fetch')]
#[Description('Download a file (or the files in a folder) from a storage mount into a site folder, in the background. Never overwrites unless asked and never writes executable files.')]
class FetchTool extends AmountTool
{
    protected function run(Delivery $delivery, Request $request): array
    {
        return $delivery->fetch(
            $this->requireString($request, 'mount'),
            $this->requireString($request, 'remote'),
            $this->requireString($request, 'to'),
            ['overwrite' => (bool) $request->get('overwrite', false), 'delete_remote' => (bool) $request->get('delete_remote', false), 'origin' => 'mcp']
        );
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mount' => $schema->string()->required()->description('Mount uuid or name'),
            'remote' => $schema->string()->required()->description('Remote file or folder path'),
            'to' => $schema->string()->required()->description('Site folder to download into'),
            'overwrite' => $schema->boolean()->description('Replace existing files'),
            'delete_remote' => $schema->boolean()->description('Delete the remote copy after a verified download'),
        ];
    }
}
