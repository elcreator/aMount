<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.files')]
#[Description('Read-only. What is offloaded for one rule: 50 files per page (newest first) with id, local_path, remote_path, size, status (pending/uploaded/failed/...), visibility and url. Only files the manager can still see in the file manager appear. Use `q` to search by path, `page` to continue (see `pages`). The `id` values feed amount.recall and amount.delete_remote.')]
class FilesTool extends AmountTool
{
    protected function run(Delivery $delivery, array $args): array
    {
        return Operations::files($delivery, $args);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'rule' => $schema->string()->required()->description('Rule uuid from amount.rules'),
            'q' => $schema->string()->description('Part of the local path, e.g. "backup"'),
            'status' => $schema->string()->description('Only this status, e.g. "uploaded"'),
            'page' => $schema->integer()->description('Page number, from 1'),
        ];
    }
}
