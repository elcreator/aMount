<?php

namespace Elcreator\aMount\Facades;

use Elcreator\aMount\Services\Actor;
use Elcreator\aMount\Services\Delivery;

/**
 * The one-line entry point for other packages:
 *
 *     aMount::for($userId)->deliver('assets/images/a.png', 'my dropbox');
 *
 * Not a Laravel facade on purpose: a facade needs a container binding, and this
 * must keep working from a worker or a console command where the provider may
 * not have booted.
 */
final class aMount
{
    public static function for(int $userId): Delivery
    {
        return Delivery::for($userId);
    }

    /** The signed-in manager. */
    public static function current(): Delivery
    {
        return Delivery::for(Actor::current()->userId());
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        return Delivery::for(Actor::current()->userId())->{$name}(...$arguments);
    }
}
