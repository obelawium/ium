<?php

declare(strict_types=1);

namespace Obelaw\Ium\Modules;

use InvalidArgumentException;
use Obelaw\Ium\Abstracts\Domain;

/**
 * Immutable descriptor for a registered Obelawium domain module.
 */
final readonly class Module
{
    /**
     * @param string $name Unique module slug (e.g. 'accounting', 'inventory').
     * @param class-string<Domain> $domain Domain gateway class extending Obelaw\Ium\Abstracts\Domain.
     * @param ModuleScope $scope Persistence scope (Central, Tenant, or Both).
     * @param list<string> $dependencies Slugs of modules that must be registered and migrated first.
     * @param list<string> $migrationPaths Absolute paths to the module's migration directories.
     * @param string|null $version Optional semantic version.
     * @param string|null $description Optional module description.
     */
    public function __construct(
        public string $name,
        public string $domain,
        public ModuleScope $scope = ModuleScope::Tenant,
        public array $dependencies = [],
        public array $migrationPaths = [],
        public ?string $version = null,
        public ?string $description = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Module name cannot be empty.');
        }

        if (! is_subclass_of($domain, Domain::class)) {
            throw new InvalidArgumentException(
                "Module [{$name}] domain [{$domain}] must be a subclass of " . Domain::class . '.'
            );
        }
    }

    /**
     * Named factory for readable module definitions.
     *
     * @param class-string<Domain> $domain
     * @param list<string> $dependencies
     * @param list<string>|string $migrationPaths
     */
    public static function make(
        string $name,
        string $domain,
        ModuleScope $scope = ModuleScope::Tenant,
        array $dependencies = [],
        array|string $migrationPaths = [],
        ?string $version = null,
        ?string $description = null,
    ): self {
        $paths = is_string($migrationPaths)
            ? ($migrationPaths !== '' ? [$migrationPaths] : [])
            : array_values(array_filter($migrationPaths, static fn (string $p): bool => $p !== ''));

        return new self(
            name: $name,
            domain: $domain,
            scope: $scope,
            dependencies: array_values(array_unique($dependencies)),
            migrationPaths: $paths,
            version: $version,
            description: $description,
        );
    }
}
