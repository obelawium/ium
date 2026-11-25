<?php

declare(strict_types=1);

namespace Obelaw\Ium\Modules;

use Obelaw\Ium\Exceptions\CircularModuleDependencyException;
use Obelaw\Ium\Exceptions\DuplicateModuleException;
use Obelaw\Ium\Exceptions\MissingModuleDependencyException;
use Obelaw\Ium\Exceptions\ModuleNotFoundException;

/**
 * Container-managed registry of Obelawium modules.
 *
 * Enforces uniqueness, performs topological dependency sorting with cycle
 * detection, and manages host-application migration overrides.
 */
final class ModuleRegistry
{
    /**
     * Registered modules keyed by slug.
     *
     * @var array<string, Module>
     */
    private array $modules = [];

    /**
     * Module slugs whose migrations are managed directly by the host app (Option B).
     *
     * @var array<string, true>
     */
    private array $ignoredMigrations = [];

    /**
     * Custom migration directory overrides keyed by module slug.
     *
     * @var array<string, list<string>>
     */
    private array $customMigrationPaths = [];

    /**
     * Core infrastructure migration paths (e.g. `ium_sequences`, `ium_outbox_messages`)
     * prepended before domain module migrations in both central and tenant databases.
     *
     * @var list<string>
     */
    private array $coreMigrationPaths = [];

    /**
     * Register a core infrastructure migration directory.
     */
    public function addCoreMigrationPath(string $path): self
    {
        if (trim($path) !== '' && ! in_array($path, $this->coreMigrationPaths, true)) {
            $this->coreMigrationPaths[] = $path;
        }

        return $this;
    }

    /**
     * Register a new module descriptor.
     *
     * @throws DuplicateModuleException When a module with the same name is already registered.
     */
    public function register(Module $module): self
    {
        if (isset($this->modules[$module->name])) {
            throw DuplicateModuleException::forName($module->name);
        }

        $this->modules[$module->name] = $module;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    public function find(string $name): ?Module
    {
        return $this->modules[$name] ?? null;
    }

    /**
     * @throws ModuleNotFoundException
     */
    public function get(string $name): Module
    {
        return $this->modules[$name] ?? throw ModuleNotFoundException::forName($name);
    }

    /**
     * @return array<string, Module>
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * Mark one or more modules as having host-managed migrations (Option B).
     *
     * Both `php artisan migrate` and all `ium:*` migration commands skip ignored modules.
     */
    public function ignoreMigrations(string ...$modules): self
    {
        foreach ($modules as $name) {
            $this->ignoredMigrations[$name] = true;
        }

        return $this;
    }

    public function isIgnoringMigrations(string $module): bool
    {
        return isset($this->ignoredMigrations[$module]);
    }

    /**
     * Override the migration directory path(s) for a specific module.
     *
     * @param string|list<string> $paths
     */
    public function useMigrationPath(string $module, string|array $paths): self
    {
        $normalized = is_string($paths) ? [$paths] : array_values($paths);

        $this->customMigrationPaths[$module] = array_values(
            array_filter($normalized, static fn (string $path): bool => trim($path) !== '')
        );

        return $this;
    }

    /**
     * Resolve effective migration paths for a single module.
     *
     * @return list<string>
     */
    public function migrationPathsFor(string $name): array
    {
        if ($this->isIgnoringMigrations($name)) {
            return [];
        }

        $module = $this->get($name);

        if (isset($this->customMigrationPaths[$name])) {
            return $this->customMigrationPaths[$name];
        }

        return $module->migrationPaths;
    }

    /**
     * Return registered modules sorted in deterministic topological dependency order.
     *
     * @param list<string>|null $only Optional subset of module slugs to filter after ordering.
     *
     * @return list<Module>
     *
     * @throws MissingModuleDependencyException
     * @throws CircularModuleDependencyException
     * @throws ModuleNotFoundException
     */
    public function ordered(?ModuleScope $scope = null, ?array $only = null): array
    {
        if ($only !== null) {
            foreach ($only as $requested) {
                if (! isset($this->modules[$requested])) {
                    throw ModuleNotFoundException::forName($requested);
                }
            }
        }

        // 0 = unvisited, 1 = visiting (on stack), 2 = visited
        $state = [];
        $stack = [];
        $sorted = [];

        $visit = function (string $name) use (&$visit, &$state, &$stack, &$sorted): void {
            $currentState = $state[$name] ?? 0;

            if ($currentState === 2) {
                return;
            }

            if ($currentState === 1) {
                $cycleStart = array_search($name, $stack, true);
                $cycle = array_slice($stack, $cycleStart === false ? 0 : $cycleStart);
                $cycle[] = $name;

                throw CircularModuleDependencyException::forCycle($cycle);
            }

            $state[$name] = 1;
            $stack[] = $name;

            $module = $this->modules[$name];

            foreach ($module->dependencies as $dependency) {
                if (! isset($this->modules[$dependency])) {
                    throw MissingModuleDependencyException::forModule($name, $dependency);
                }

                $visit($dependency);
            }

            array_pop($stack);
            $state[$name] = 2;
            $sorted[] = $module;
        };

        foreach (array_keys($this->modules) as $name) {
            $visit($name);
        }

        if ($only !== null) {
            $allowed = array_flip($only);
            $sorted = array_values(array_filter(
                $sorted,
                static fn (Module $m): bool => isset($allowed[$m->name])
            ));
        }

        if ($scope !== null) {
            $sorted = array_values(array_filter(
                $sorted,
                static fn (Module $m): bool => match ($scope) {
                    ModuleScope::Central => $m->scope->runsInCentral(),
                    ModuleScope::Tenant => $m->scope->runsInTenant(),
                    ModuleScope::Both => true,
                }
            ));
        }

        return $sorted;
    }

    /**
     * Collect ordered migration paths for `php artisan migrate` / `ium:migrate`.
     *
     * In single-tenant mode, includes core infrastructure + all non-ignored modules.
     * In multi-tenant mode, includes central modules only (`Central` and `Both`).
     *
     * @param list<string>|null $only
     *
     * @return list<string>
     */
    public function migrationPaths(bool $multiTenant = false, ?array $only = null): array
    {
        $scope = $multiTenant ? ModuleScope::Central : null;
        $paths = $this->collectPaths($this->ordered($scope, $only));

        if (! $multiTenant && $only === null && ! $this->isIgnoringMigrations('core')) {
            return $this->prependCorePaths($paths);
        }

        return $paths;
    }

    /**
     * Collect ordered migration paths for tenant-scoped modules (`Tenant` and `Both`).
     *
     * Automatically prepends core tenant infrastructure (`ium_sequences`, `ium_outbox_messages`)
     * unless `core` migrations are explicitly ignored.
     *
     * @param list<string>|null $only
     *
     * @return list<string>
     */
    public function tenantMigrationPaths(?array $only = null): array
    {
        $paths = $this->collectPaths($this->ordered(ModuleScope::Tenant, $only));

        if ($only === null && ! $this->isIgnoringMigrations('core')) {
            return $this->prependCorePaths($paths);
        }

        return $paths;
    }

    /**
     * Reset all registered modules and migration overrides (preserving core infrastructure paths).
     */
    public function reset(): self
    {
        $this->modules = [];
        $this->ignoredMigrations = [];
        $this->customMigrationPaths = [];

        return $this;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function prependCorePaths(array $paths): array
    {
        $core = array_values(array_filter(
            $this->coreMigrationPaths,
            static fn (string $path): bool => is_dir($path) || is_file($path),
        ));

        return array_values(array_unique(array_merge($core, $paths)));
    }

    /**
     * @param list<Module> $modules
     *
     * @return list<string>
     */
    private function collectPaths(array $modules): array
    {
        $paths = [];

        foreach ($modules as $module) {
            foreach ($this->migrationPathsFor($module->name) as $path) {
                if (is_dir($path) || is_file($path)) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }
}
