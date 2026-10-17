<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.runs')]
#[Description('Read-only. The newest runs (at most 50, newest first) the manager started or owns, each shaped like amount.status. Use active=true for "what is running now", or `rule` (uuid from amount.rules) for one rule\'s history. Find a forgotten run uuid here.')]
class RunsTool extends AmountTool
{
    protected function run(Delivery $delivery, array $args): array
    {
        return Operations::runs($delivery, $args);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'active' => $schema->boolean()->description('Only queued or running runs. Default false.'),
            'rule' => $schema->string()->description('Only the runs of this rule (uuid from amount.rules)'),
        ];
    }
}
