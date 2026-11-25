<?php

declare(strict_types=1);

namespace Obelaw\Ium\Abstracts;

use Obelaw\Ium\Engine\ObelawConfigManager;

/**
 * Base class for all Obelawium domains.
 *
 * A domain groups related services and receives the shared configuration
 * manager at instantiation.
 */
abstract class Domain
{
    public function __construct(
        protected readonly ObelawConfigManager $config
    ) {}

    /**
     * Access the shared Obelawium configuration manager.
     */
    public function config(): ObelawConfigManager
    {
        return $this->config;
    }
}
