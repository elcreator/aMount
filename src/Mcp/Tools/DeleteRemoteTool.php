<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Services\Delivery;
use EvolutionCMS\eMCP\Contracts\WritesSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.delete_remote')]
#[Description('DESTRUCTIVE and irreversible: delete one offloaded object from the storage mount and forget it (the local file is not touched). Refused unless confirm=true, so first tell the person exactly which file (amount.files) will be deleted and call with confirm=true only after they agreed. If the file exists only remotely, it is gone for good.')]
class DeleteRemoteTool extends AmountTool implements WritesSite
{
    protected function run(Delivery $delivery, array $args): array
    {
        return Operations::deleteRemote($delivery, $args);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'file' => $schema->integer()->required()->description('File id from amount.files'),
            'confirm' => $schema->boolean()->required()->description('Must be true, set only after the person confirmed this exact file'),
        ];
    }
}
