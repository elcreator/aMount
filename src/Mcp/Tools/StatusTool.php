<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.status')]
#[Description('Read-only. Progress of ONE run started by amount.deliver, amount.fetch, amount.recall or amount.measure: status (queued/scanning/uploading/done/failed/cancelled), finished, percent, files and bytes done/total, current_file, message and, for a finished measurement, `usage`. This is the way to poll long operations; repeat every few seconds until `finished` is true. Only runs the manager started or owns are visible.')]
class StatusTool extends AmountTool
{
    protected function run(Delivery $delivery, array $args): array
    {
        return Operations::status($delivery, $args);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'run' => $schema->string()->required()->description('Run uuid returned by a starting tool'),
        ];
    }
}
