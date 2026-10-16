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
 * eMCP runs the request as the token owner, so `Actor::current()` is that
 * manager. An ambiguous mount is reported with its candidates in the message so
 * the agent can ask the user which one was meant.
 */
abstract class AmountTool extends Tool
{
    /** @throws AmountException */
    abstract protected function run(Delivery $delivery, Request $request): array;

    public function handle(Request $request): ResponseFactory
    {
        try {
            $actor = Actor::current()->authorize();

            return Response::structured($this->run(Delivery::for($actor->userId()), $request));
        } catch (AmountException $e) {
            throw ValidationException::withMessages([$e->error => $e->getMessage()]);
        }
    }

    protected function requireString(Request $request, string $name): string
    {
        $value = trim((string) $request->get($name, ''));

        if ($value === '') {
            throw ValidationException::withMessages([$name => "{$name} is required."]);
        }

        return $value;
    }
}
