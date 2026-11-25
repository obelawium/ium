<?php

declare(strict_types=1);

namespace Obelaw\Ium\Contracts;

use InvalidArgumentException;

/**
 * Immutable identity descriptor for an active tenant.
 */
final readonly class TenantIdentity
{
    /**
     * @param string $id Unique tenant identifier (UUID, ULID, or stringified integer).
     * @param string $key Human-readable tenant slug/key (e.g. 'acme').
     * @param string|null $connection Laravel database connection name dedicated to this tenant.
     * @param list<string>|null $enabledModules Explicit active module slugs (`null` means all tenant modules).
     * @param array<string, mixed> $metadata Additional tenant metadata.
     */
    public function __construct(
        public string $id,
        public string $key,
        public ?string $connection = null,
        public ?array $enabledModules = null,
        public array $metadata = [],
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Tenant identity ID cannot be empty.');
        }

        if (trim($key) === '') {
            throw new InvalidArgumentException('Tenant identity key cannot be empty.');
        }
    }
}
