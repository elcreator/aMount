<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.measure')]
#[Description('Count how much space something uses ("how much does the S3 mount use"). Read-only but slow, so it runs in the background and returns a run uuid: poll amount.status until `finished`, the result is its `usage`. Give `mount` for a whole mount (a shared site mount needs an administrator; other managers measure their own `rule`), or `rule` for a rule\'s remote folder, or `rule` + local=true for its local folder. `path` narrows to a sub-folder.')]
class MeasureTool extends AmountTool
{
    protected function run(Delivery $delivery, array $args): array
    {
        return Operations::measure($delivery, $args);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mount' => $schema->string()->description('Mount uuid or name (when no rule is given)'),
            'rule' => $schema->string()->description('Rule uuid from amount.rules'),
            'path' => $schema->string()->description('Sub-folder to count, e.g. "Backups" (default: everything)'),
            'local' => $schema->boolean()->description('With `rule`: count the local site folder instead of the remote one'),
        ];
    }
}
