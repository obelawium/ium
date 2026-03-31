<?php

namespace Obelaw\Ium\Engine;

use Obelaw\Ium\Contracts\IumConfigEnum;
use Obelaw\Ium\Engine\GlobalConfigManager;

final class ObelawConfigManager
{
    private static ?self $instance = null;

    private array $configs = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function get(IumConfigEnum|string $config, mixed $default = null): mixed
    {
        $key = $config instanceof IumConfigEnum ? $config->value : $config;

        return $this->configs[$key] ?? $default;
    }

    public function set(IumConfigEnum|string $config, mixed $value, ?bool $global = null): self
    {
        $key = $config instanceof IumConfigEnum ? $config->value : $config;

        $this->configs[$key] = $value;

        if ($global) {
            GlobalConfigManager::set($key, $value);
        }

        return $this;
    }

    public function defaults(IumConfigEnum|string $config, mixed $value): self
    {
        $key = $config instanceof IumConfigEnum ? $config->value : $config;

        if (!isset($this->configs[$key])) {
            $this->configs[$key] = $value;
        }

        return $this;
    }

    public function all(): array
    {
        return $this->configs;
    }

    public function reset(): self
    {
        $this->configs = [];

        return $this;
    }
}
