<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.deliver')]
#[Description('Send a file or folder from the site to a storage mount in the background. The manager must be able to see it in the file manager. If the mount is unclear the call fails and lists the candidates: ask which one, then call again. Returns a run id for amount.status.')]
class DeliverTool extends AmountTool
{
    protected function run(Delivery $delivery, Request $request): array
    {
        return $delivery->deliver(
            $this->requireString($request, 'path'),
            (string) $request->get('mount', '') ?: null,
            (string) $request->get('folder', '') ?: null,
            ['move' => (bool) $request->get('move', false), 'public' => (bool) $request->get('public', false), 'origin' => 'mcp']
        );
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->required()->description('Site path, e.g. assets/images/cat.png'),
            'mount' => $schema->string()->description('Mount uuid or a name such as "google drive"'),
            'folder' => $schema->string()->description('Remote folder (default: the top)'),
            'move' => $schema->boolean()->description('Delete the local file after a verified upload'),
            'public' => $schema->boolean()->description('Keep the file publicly reachable at its URL'),
        ];
    }
}
