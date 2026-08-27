<?php

namespace Obelaw\Ium\Abstracts;

use Obelaw\Ium\Engine\ObelawConfigManager;

/**
 * Base class for all Obelawium domains.
 *
 * A domain groups related services and receives the shared configuration
 * manager at instantiation.
 */
class Domain
{
    public function __construct(private ObelawConfigManager $config) {}
}
