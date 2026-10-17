<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Services\Delivery;
use EvolutionCMS\eMCP\Contracts\WritesSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.fetch')]
#[Description('Download a file (or the files directly inside a folder, at most 200) from a storage mount into a site folder, in the background; returns a run uuid for amount.status. Use it for any remote path, including objects aMount never uploaded. Never overwrites unless overwrite=true, never writes executable files, and the destination must be a folder the manager can modify in the file manager. For files aMount itself offloaded, amount.recall by id is the alternative.')]
class FetchTool extends AmountTool implements WritesSite
{
    protected function run(Delivery $delivery, array $args): array
    {
        return Operations::fetch($delivery, $args);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mount' => $schema->string()->required()->description('Mount uuid (preferred) or name'),
            'remote' => $schema->string()->required()->description('Remote file or folder, e.g. "Backups/db-2026-10-16.sql.gz"'),
            'to' => $schema->string()->required()->description('Site folder to download into, e.g. "assets/backup"'),
            'overwrite' => $schema->boolean()->description('Replace files that already exist. Default false.'),
            'delete_remote' => $schema->boolean()->description('Delete the remote copy after a verified download (destructive). Default false.'),
        ];
    }
}
