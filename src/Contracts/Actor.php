<?php

declare(strict_types=1);

namespace Obelaw\Ium\Contracts;

use InvalidArgumentException;

/**
 * Immutable domain actor value object used for auditing and Maker-Checker governance.
 */
final readonly class Actor
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $type,
        public string $id,
        public ?string $name = null,
        public array $metadata = [],
    ) {
        if (trim($type) === '') {
            throw new InvalidArgumentException('Actor type cannot be empty.');
        }

        if (trim($id) === '') {
            throw new InvalidArgumentException('Actor id cannot be empty.');
        }
    }

    public static function system(string $id = 'system', ?string $name = 'System'): self
    {
        return new self(type: 'system', id: $id, name: $name);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function user(string|int $id, ?string $name = null, string $type = 'user', array $metadata = []): self
    {
        return new self(type: $type, id: (string) $id, name: $name, metadata: $metadata);
    }

    public function identifier(): string
    {
        return "{$this->type}:{$this->id}";
    }
}
