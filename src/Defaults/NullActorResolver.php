<?php

declare(strict_types=1);

namespace Obelaw\Ium\Defaults;

use Obelaw\Ium\Contracts\Actor;
use Obelaw\Ium\Contracts\ActorResolver;

/**
 * Zero-configuration default ActorResolver returning the system actor.
 */
final class NullActorResolver implements ActorResolver
{
    public function resolve(): Actor
    {
        return Actor::system();
    }

    public function id(): string
    {
        return $this->resolve()->id;
    }
}
