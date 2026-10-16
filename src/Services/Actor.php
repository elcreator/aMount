<?php

namespace Elcreator\aMount\Services;

use Elcreator\aMount\Support\Principal;
use Elcreator\aMount\Support\Scope;

/**
 * The manager the module is acting for.
 *
 * Built from the session for a click and from a user id for everything else (a
 * worker, the Delivery API, an AI agent behind eMCP). Either way the answers
 * come from {@see Principal}, i.e. from the database as the account stands now,
 * so the page and a queued job can never disagree about what somebody may do.
 */
final class Actor
{
    private ?Principal $principal = null;

    private ?Scope $scope = null;

    private function __construct(private readonly int $userId)
    {
    }

    /** The signed-in manager, from the session (also what eMCP impersonates). */
    public static function current(): self
    {
        return new self((int) evo()->getLoginUserID('mgr'));
    }

    public static function for(int $userId): self
    {
        return new self($userId);
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function principal(): Principal
    {
        return $this->principal ??= Principal::forUser($this->userId);
    }

    public function scope(): Scope
    {
        return $this->scope ??= Scope::for($this->principal());
    }

    public function mayUse(): bool
    {
        return $this->userId > 0 && $this->principal()->mayUse();
    }

    public function mayManage(): bool
    {
        return $this->userId > 0 && $this->principal()->mayManage();
    }

    /** @throws AmountException */
    public function authorize(): self
    {
        if (!$this->mayUse()) {
            throw AmountException::forbidden();
        }

        return $this;
    }
}
