<?php

namespace Lightspeed\Facades;

use Illuminate\Support\Facades\Facade;
use Lightspeed\Auth\GrantManager;

/**
 * @method static \Lightspeed\Auth\PendingGrant tag(array|string $tags)
 * @method static void revoke(array|string $tags)
 *
 * @see \Lightspeed\Auth\GrantManager
 */
class Lightspeed extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GrantManager::class;
    }
}
