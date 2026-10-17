<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.mounts')]
#[Description('Read-only. List the storage mounts (Google Drive, Dropbox, S3, ...) the acting manager may use: uuid, name, driver, scope (personal/site/config) and health status. Never returns credentials. With `name` (e.g. "my google drive") it resolves one mount; if several match the call fails with `candidates=[{uuid,name,...}]` - ask the person which one, then use that uuid in later calls.')]
class MountsTool extends AmountTool
{
    protected function run(Delivery $delivery, array $args): array
    {
        return Operations::mounts($delivery, $args);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Optional mount name or kind, e.g. "google drive" or "S3 backups"'),
        ];
    }
}
