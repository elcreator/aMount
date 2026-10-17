<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.rules')]
#[Description('Read-only. The standing folder rules (site folder -> mount, schedule, mode copy/move, visibility, offloaded totals, last run) the manager owns. Rules are created in the aMount page; one-off deliveries from amount.deliver are not listed. Use a rule uuid with amount.files, amount.runs and amount.measure.')]
class RulesTool extends AmountTool
{
    protected function run(Delivery $delivery, array $args): array
    {
        return Operations::rules($delivery, $args);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
