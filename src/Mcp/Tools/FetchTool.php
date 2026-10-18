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
#[Description('Download a file, or the files in a folder (recursive=true keeps subfolders, up to 2000 files; else the files directly inside, up to 200), from a storage mount into a site folder, in the background; returns a run uuid for amount.status. The mount may be one of the person\'s own computers (amount.computers): "from my computer mypc, folder 2025/08 of sqlbackups, into backups" is mount="mypc", remote="sqlbackups/2025/08", to="backups", recursive=true. When a name is taken here, a newer file replaces an older one only if destructive tools are allowed for this call; otherwise it is kept, and every file not written is listed with its reason in amount.status. Never writes executable files; the destination must be a folder the manager can modify; files from a computer fill a site folder only up to its quota.')]
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
            'recursive' => $schema->boolean()->description('Include subfolders, recreating them under `to`. Default false.'),
            'on_conflict' => $schema->string()->description('"skip" keeps a file already here; "newer" lets a newer file replace an older one (needs destructive permission). Default: newer when destructive tools are allowed, else skip.'),
            'site' => $schema->string()->description('The site the person named ("site.com"): refused if this installation does not serve it.'),
            'delete_remote' => $schema->boolean()->description('Delete the remote copy after a verified download (destructive). Default false.'),
        ];
    }
}
