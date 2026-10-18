<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.computers')]
#[Description('The person\'s own computers (the ai.artur.work program on Windows, macOS or Linux) and the ones other accounts shared with them: which are online, the folders each shares with this site (name, this site\'s access - read, write, or "" when not approved yet - quota and use), and this site\'s code. Use a computer\'s name as `mount` in amount.fetch / amount.deliver with paths "<shared folder>/<path>". A folder this site may not use yet is approved by the person at that computer, who must compare the code shown there with `site.code`: tell them so, and try again once they allowed it. `site.domains` are the domains this installation serves.')]
class ComputersTool extends AmountTool
{
    protected function run(Delivery $delivery, array $args): array
    {
        return Operations::computers($delivery, $args);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
