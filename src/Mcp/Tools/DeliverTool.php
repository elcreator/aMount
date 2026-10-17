<?php

namespace Elcreator\aMount\Mcp\Tools;

use Elcreator\aMount\Mcp\AmountTool;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Services\Delivery;
use EvolutionCMS\eMCP\Contracts\WritesSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('amount.deliver')]
#[Description('Send a file or a whole folder from the site to a storage mount (back up / put on Google Drive). Runs in the background and returns at once with a run uuid: poll amount.status with it until `finished` is true. The manager must be able to see the path in the file manager, otherwise the call is refused. If `mount` is omitted or unclear the call fails with `candidates=[{uuid,name,...}]`: ask the person which one, then call again with that uuid. With move=true the local copy is deleted after a verified upload (destructive; only when asked).')]
class DeliverTool extends AmountTool implements WritesSite
{
    protected function run(Delivery $delivery, array $args): array
    {
        return Operations::deliver($delivery, $args);
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->required()->description('Site-relative file or folder, e.g. "assets/images/cat.png" or "assets/backup"'),
            'mount' => $schema->string()->description('Mount uuid (preferred) or a name such as "google drive"'),
            'folder' => $schema->string()->description('Remote folder to put it in, e.g. "Pictures/Cats" (default: the top)'),
            'move' => $schema->boolean()->description('Delete the local file after a verified upload. Default false.'),
            'public' => $schema->boolean()->description('Keep the file publicly reachable at its site URL. Default false (private).'),
        ];
    }
}
