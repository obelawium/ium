<?php

declare(strict_types=1);

namespace Obelaw\Ium\Contracts;

use Obelaw\Ium\Exceptions\AuthorizationException;

/**
 * Domain-level authorization contract keeping governance rules inside IUM domains.
 */
interface Authorizer
{
    public function allows(string $ability, mixed $subject = null, ?Actor $actor = null): bool;

    /**
     * @throws AuthorizationException When the actor is not permitted to perform the ability.
     */
    public function authorize(string $ability, mixed $subject = null, ?Actor $actor = null): void;
}
