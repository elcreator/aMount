<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.status')]
#[Description('Progress of a delivery or download started with amount.deliver / amount.fetch.')]
class StatusTool extends AmountTool
{
    protected function run(Delivery $delivery, Request $request): array
    {
        return $delivery->status($this->requireString($request, 'run'));
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'run' => $schema->string()->required()->description('Run id returned by amount.deliver / amount.fetch'),
        ];
    }
}
