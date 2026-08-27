<?php

namespace Obelaw\Ium;

use Obelaw\Ium\Abstracts\Domain;
use Obelaw\Ium\Engine\IumDispatcher;
use Obelaw\Ium\Engine\ObelawConfigManager;

final class ObelawiumManager
{
    private static ?self $instance = null;

    public readonly ObelawConfigManager $config;

    private array $domains = [];

    private function __construct()
    {
        $this->config = ObelawConfigManager::getInstance();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function config(): ObelawConfigManager
    {
        return $this->config;
    }

    public function __invoke(array $configs = []): self
    {
        $this->config->merge($configs);

        return $this;
    }

    public function registerDomain(string $name, string $domain): self
    {
        if (!is_subclass_of($domain, Domain::class)) {
            throw new \InvalidArgumentException("Domain [{$name}] must be a subclass of " . Domain::class);
        }

        $instance = self::getInstance();
        $instance->domains[$name] = $domain;

        return $instance;
    }

    public function getDomain(string $name): ?string
    {
        return $this->domains[$name] ?? null;
    }

    public function hasDomain(string $name): bool
    {
        return isset($this->domains[$name]);
    }

    public function getDomains(): array
    {
        return $this->domains;
    }

    private function getDomainInstance(string $name): mixed
    {
        $domain = $this->getDomain($name);

        if ($domain === null) {
            throw new \InvalidArgumentException("Domain [{$name}] is not registered.");
        }

        return new $domain($this->config());
    }

    public function __call(string $name, array $arguments): mixed
    {
        $domain = $this->getDomain($name);

        if ($domain === null) {
            throw new \BadMethodCallException("Domain [{$name}] is not registered.");
        }

        return $this->getDomainInstance($name);
    }

    public function call($domain, $service, $method = null, $data = null): mixed
    {
        return $this->dispatcher->dispatch(
            $this->getDomainInstance($domain),
            $domain,
            $service,
            $method,
            $data,
        );
    }

    public function reset(): self
    {
        $this->config->reset();
        $this->domains = [];

        return $this;
    }
}
