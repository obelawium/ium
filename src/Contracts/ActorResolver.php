<?php

declare(strict_types=1);

namespace Obelaw\Ium\Contracts;

/**
 * Resolves the actor executing the current domain operation.
 */
interface ActorResolver
{
    public function resolve(): Actor;

    public function id(): string;
}
