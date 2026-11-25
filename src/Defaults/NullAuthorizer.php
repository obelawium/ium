<?php

declare(strict_types=1);

namespace Obelaw\Ium\Defaults;

use Obelaw\Ium\Contracts\Actor;
use Obelaw\Ium\Contracts\Authorizer;

/**
 * Zero-configuration default Authorizer permitting all domain actions in headless mode.
 */
final class NullAuthorizer implements Authorizer
{
    public function allows(string $ability, mixed $subject = null, ?Actor $actor = null): bool
    {
        return true;
    }

    public function authorize(string $ability, mixed $subject = null, ?Actor $actor = null): void
    {
        // Permissive default when no host Authorizer is configured.
    }
}
