<?php

declare(strict_types=1);

namespace Obelaw\Ium\Contracts;

/**
 * Scoped key/value settings store contract (implemented per-tenant or via o-configs).
 */
interface SettingsRepository
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function forget(string $key): void;
}
