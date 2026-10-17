<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Services\Delivery;
use EvolutionCMS\eMCP\Contracts\WritesSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.recall')]
#[Description('Bring files that aMount offloaded earlier back to the site (ids from amount.files, at most 200, all from the same rule). Background run: returns a run uuid for amount.status. Without `to` each file goes back to its original place; with `to` into that site folder. Never overwrites unless overwrite=true, never writes executable files, and the destination must be modifiable by the manager in the file manager.')]
class RecallTool extends AmountTool implements WritesSite
{
    protected function run(Delivery $delivery, array $args): array
    {
        return Operations::recall($delivery, $args);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'files' => $schema->array()->items($schema->integer())->required()->description('File ids from amount.files, e.g. [12, 13]'),
            'to' => $schema->string()->description('Site folder to restore into, e.g. "assets/restored" (default: original location)'),
            'overwrite' => $schema->boolean()->description('Replace files that already exist. Default false.'),
            'delete_remote' => $schema->boolean()->description('Delete the remote copy after a verified download (destructive). Default false.'),
        ];
    }
}
