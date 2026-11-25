<?php

declare(strict_types=1);

namespace Obelaw\Ium;

use BadMethodCallException;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Obelaw\Ium\Abstracts\Domain;
use Obelaw\Ium\Contracts\Actor;
use Obelaw\Ium\Contracts\ActorResolver;
use Obelaw\Ium\Contracts\Authorizer;
use Obelaw\Ium\Contracts\SettingsRepository;
use Obelaw\Ium\Contracts\TenantContext;
use Obelaw\Ium\Defaults\NullActorResolver;
use Obelaw\Ium\Defaults\NullAuthorizer;
use Obelaw\Ium\Defaults\NullSettingsRepository;
use Obelaw\Ium\Defaults\NullTenantContext;
use Obelaw\Ium\Engine\GlobalConfigManager;
use Obelaw\Ium\Engine\ObelawConfigManager;
use Obelaw\Ium\Modules\Module;
use Obelaw\Ium\Modules\ModuleRegistry;
use Obelaw\Ium\Outbox\Outbox;
use Obelaw\Ium\Support\Sequence;

/**
 * Central container-managed registry and gateway for Obelawium modules and domains.
 *
 * Holds registered modules and domain classes, owns the shared configuration
 * manager, resolves environment contracts, and executes transactions on the
 * active tenant connection.
 */
final class ObelawiumManager
{
    /**
     * Shared configuration manager.
     */
    public readonly ObelawConfigManager $config;

    /**
     * Module registry managing dependencies, scopes, and migration paths.
     */
    public readonly ModuleRegistry $modules;

    /**
     * Registered domains, keyed by domain name.
     *
     * @var array<string, class-string<Domain>>
     */
    private array $domains = [];

    public function __construct(
        ?ObelawConfigManager $config = null,
        ?ModuleRegistry $modules = null,
    ) {
        $this->config = $config ?? ObelawConfigManager::getInstance();
        $this->modules = $modules ?? new ModuleRegistry();
    }

    /**
     * Resolve the active container-bound manager instance.
     *
     * Bound to the active Laravel Container so Laravel Octane and test suites
     * never share stale static state.
     */
    public static function getInstance(): self
    {
        $container = Container::getInstance();

        if (! $container->bound(self::class)) {
            $container->singleton(self::class, static fn (Container $app): self => new self(
                $app->bound(ObelawConfigManager::class)
                    ? $app->make(ObelawConfigManager::class)
                    : ObelawConfigManager::getInstance(),
                $app->bound(ModuleRegistry::class)
                    ? $app->make(ModuleRegistry::class)
                    : new ModuleRegistry(),
            ));
        }

        return $container->make(self::class);
    }

    /**
     * Get the shared configuration manager.
     */
    public function config(): ObelawConfigManager
    {
        return $this->config;
    }

    /**
     * Get the module registry.
     */
    public function modules(): ModuleRegistry
    {
        return $this->modules;
    }

    /**
     * Resolve the active TenantContext contract.
     */
    public function tenantContext(): TenantContext
    {
        $container = Container::getInstance();

        return $container->bound(TenantContext::class)
            ? $container->make(TenantContext::class)
            : new NullTenantContext();
    }

    /**
     * Resolve the active ActorResolver contract.
     */
    public function actorResolver(): ActorResolver
    {
        $container = Container::getInstance();

        return $container->bound(ActorResolver::class)
            ? $container->make(ActorResolver::class)
            : new NullActorResolver();
    }

    /**
     * Resolve the current domain Actor.
     */
    public function actor(): Actor
    {
        return $this->actorResolver()->resolve();
    }

    /**
     * Resolve the active Authorizer contract.
     */
    public function authorizer(): Authorizer
    {
        $container = Container::getInstance();

        return $container->bound(Authorizer::class)
            ? $container->make(Authorizer::class)
            : new NullAuthorizer();
    }

    /**
     * Resolve the active SettingsRepository contract.
     */
    public function settings(): SettingsRepository
    {
        $container = Container::getInstance();

        return $container->bound(SettingsRepository::class)
            ? $container->make(SettingsRepository::class)
            : new NullSettingsRepository();
    }

    /**
     * Resolve the concurrency-safe, tenant-isolated Sequence generator.
     */
    public function sequence(): Sequence
    {
        $container = Container::getInstance();

        return $container->bound(Sequence::class)
            ? $container->make(Sequence::class)
            : new Sequence();
    }

    /**
     * Resolve the transactional Outbox service.
     */
    public function outbox(): Outbox
    {
        $container = Container::getInstance();

        return $container->bound(Outbox::class)
            ? $container->make(Outbox::class)
            : new Outbox();
    }

    /**
     * Resolve the active database connection name from TenantContext or global config.
     */
    public function connectionName(): ?string
    {
        $tenantConnection = $this->tenantContext()->connectionName();

        if ($tenantConnection !== null && $tenantConnection !== '') {
            return $tenantConnection;
        }

        $globalConnection = GlobalConfigManager::get('obelawium.db.connection');

        return is_string($globalConnection) && $globalConnection !== ''
            ? $globalConnection
            : null;
    }

    /**
     * Resolve the active Laravel database connection matching ModelBase.
     */
    public function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName());
    }

    /**
     * Execute a database transaction on the active tenant/IUM database connection.
     *
     * Guarantees transactions and ModelBase queries always run on the exact same connection.
     *
     * @template TResult
     *
     * @param callable(ConnectionInterface): TResult $callback
     *
     * @return TResult
     */
    public function transaction(callable $callback, int $attempts = 1): mixed
    {
        return $this->connection()->transaction($callback, $attempts);
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
     * Register a first-class Obelawium module (domain, scope, dependencies, migrations).
     */
    public function registerModule(Module $module): self
    {
        $this->modules->register($module);
        $this->domains[$module->name] = $module->domain;

        return $this;
    }

    /**
     * Mark module(s) as having host-managed migrations (Option B: skipped by all migration commands).
     */
    public function ignoreMigrations(string ...$modules): self
    {
        $this->modules->ignoreMigrations(...$modules);

        return $this;
    }

    /**
     * Override the migration directory path(s) for a specific module.
     *
     * @param string|list<string> $paths
     */
    public function useMigrationPath(string $module, string|array $paths): self
    {
        $this->modules->useMigrationPath($module, $paths);

        return $this;
    }

    /**
     * Register a domain class under a name.
     *
     * @deprecated Use {@see self::registerModule()} to register full module metadata.
     *
     * @param string $name Domain name used by the gateway.
     * @param class-string<Domain> $domain Domain class to register.
     *
     * @throws InvalidArgumentException When the class is not a Domain subclass.
     */
    public function registerDomain(string $name, string $domain): self
    {
        if (! is_subclass_of($domain, Domain::class)) {
            throw new InvalidArgumentException("Domain [{$name}] must be a subclass of " . Domain::class);
        }

        $this->domains[$name] = $domain;

        return $this;
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
     * @throws InvalidArgumentException When the domain is not registered.
     */
    private function getDomainInstance(string $name): Domain
    {
        $domain = $this->getDomain($name);

        if ($domain === null) {
            throw new InvalidArgumentException("Domain [{$name}] is not registered.");
        }

        return new $domain($this->config());
    }

    /**
     * Resolve a registered domain instance via magic method access.
     *
     * @param array<int, mixed> $arguments
     *
     * @throws BadMethodCallException When the domain is not registered.
     */
    public function __call(string $name, array $arguments): mixed
    {
        $domain = $this->getDomain($name);

        if ($domain === null) {
            throw new BadMethodCallException("Domain [{$name}] is not registered.");
        }

        return $this->getDomainInstance($name);
    }

    /**
     * Reset the manager: clear config, modules, and registered domains.
     */
    public function reset(): self
    {
        $this->config->reset();
        $this->modules->reset();
        $this->domains = [];

        return $this;
    }
}
