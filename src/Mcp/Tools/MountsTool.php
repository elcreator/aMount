<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.mounts')]
#[Description('List the storage mounts (Google Drive, Dropbox, S3, ...) the acting manager can send files to. Optionally resolve a spoken name such as "my google drive"; an unclear name returns the candidates to ask about.')]
class MountsTool extends AmountTool
{
    protected function run(Delivery $delivery, Request $request): array
    {
        $hint = trim((string) $request->get('name', ''));

        if ($hint === '') {
            return ['mounts' => $delivery->mounts()];
        }

        return ['mount' => $delivery->resolveMount($hint)];
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Optional: a mount name or kind, e.g. "google drive"'),
        ];
    }
}
