<?php

namespace Obelaw\Ium;

use Obelaw\Ium\Abstracts\Domain;
use Obelaw\Ium\Engine\ObelawConfigManager;

/**
 * Central registry and gateway for Obelawium domains.
 *
 * Holds the registered domain classes, owns the shared configuration
 * manager, and delegates service invocation to the domain instances.
 */
final class ObelawiumManager
{
    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    /**
     * Shared configuration manager.
     */
    public readonly ObelawConfigManager $config;

    /**
     * Registered domains, keyed by domain name.
     *
     * @var array<string, class-string<Domain>>
     */
    private array $domains = [];

    private function __construct()
    {
        $this->config = ObelawConfigManager::getInstance();
    }

    /**
     * Get the singleton manager instance.
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Get the shared configuration manager.
     */
    public function config(): ObelawConfigManager
    {
        return $this->config;
    }

    /**
     * Merge configuration values into the shared config manager.
     *
     * @param array<string, mixed> $configs Configuration values to merge.
     */
    public function __invoke(array $configs = []): self
    {
        $this->config->merge($configs);

        return $this;
    }

    /**
     * Register a domain class under a name.
     *
     * @param string $name Domain name used by the gateway.
     * @param class-string<Domain> $domain Domain class to register.
     *
     * @throws \InvalidArgumentException When the class is not a Domain subclass.
     */
    public function registerDomain(string $name, string $domain): self
    {
        if (!is_subclass_of($domain, Domain::class)) {
            throw new \InvalidArgumentException("Domain [{$name}] must be a subclass of " . Domain::class);
        }

        $instance = self::getInstance();
        $instance->domains[$name] = $domain;

        return $instance;
    }

    /**
     * Get the class registered for a domain name.
     *
     * @return class-string<Domain>|null
     */
    public function getDomain(string $name): ?string
    {
        return $this->domains[$name] ?? null;
    }

    /**
     * Determine whether a domain name is registered.
     */
    public function hasDomain(string $name): bool
    {
        return isset($this->domains[$name]);
    }

    /**
     * Get all registered domains, keyed by name.
     *
     * @return array<string, class-string<Domain>>
     */
    public function getDomains(): array
    {
        return $this->domains;
    }

    /**
     * Instantiate the domain registered under the given name.
     *
     * @throws \InvalidArgumentException When the domain is not registered.
     */
    private function getDomainInstance(string $name): Domain
    {
        $domain = $this->getDomain($name);

        if ($domain === null) {
            throw new \InvalidArgumentException("Domain [{$name}] is not registered.");
        }

        return new $domain($this->config());
    }

    /**
     * Resolve a registered domain instance via magic method access.
     *
     * @param array<int, mixed> $arguments
     *
     * @throws \BadMethodCallException When the domain is not registered.
     */
    public function __call(string $name, array $arguments): mixed
    {
        $domain = $this->getDomain($name);

        if ($domain === null) {
            throw new \BadMethodCallException("Domain [{$name}] is not registered.");
        }

        return $this->getDomainInstance($name);
    }

    /**
     * Reset the manager: clear config, domains, and dispatcher caches.
     */
    public function reset(): self
    {
        $this->config->reset();
        $this->domains = [];

        return $this;
    }
}
