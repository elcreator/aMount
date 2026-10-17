<?php

namespace Elcreator\aMount\Mcp;

use Elcreator\aMount\Services\Actor;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Delivery;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * An MCP tool over {@see Delivery}.
 *
 * eMCP runs the request as the token owner (ManagerIdentity::runAs fills the
 * manager session keys for the duration of the call), so `Actor::current()` is
 * that manager. The id is read from the identity only; no tool has a user
 * argument. The account's rights are then read from the database by
 * {@see Actor}, so what the agent may do is what the manager may do on the page.
 * A tool that changes anything also implements eMCP's WritesSite marker, so
 * eMCP's write gate (enable_write_tools + the write scope) applies first.
 */
abstract class AmountTool extends Tool
{
    /**
     * @param array<string, mixed> $args
     * @throws AmountException
     */
    abstract protected function run(Delivery $delivery, array $args): array;

    public function handle(Request $request): ResponseFactory
    {
        try {
            $actor = Actor::current()->authorize();

            return Response::structured($this->run(Delivery::for($actor->userId()), $request->all()));
        } catch (AmountException $e) {
            $messages = $e->errors ?: [$e->error => Operations::explain($e)];

            throw ValidationException::withMessages($messages);
        }
    }
}
