<?php

namespace Obelaw\Ium;

use Illuminate\Support\Traits\Macroable;
use Obelaw\Ium\Contracts\IumConfigEnum;
use Obelaw\Ium\Engine\ObelawConfigManager;

final class ObelawiumManager
{
    use Macroable;

    private static ?self $instance = null;

    public readonly ObelawConfigManager $config;

    private array $modules = [];

    public function __construct()
    {
        $this->config = ObelawConfigManager::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function config(): ObelawConfigManager
    {
        return $this->config;
    }

    public function __invoke(array $configs = []): self
    {
        if (!empty($configs)) {
            foreach ($configs as $module => $moduleConfig) {
                if ($module instanceof IumConfigEnum) {
                    $moduleName = $module->module();
                    $this->config->merge($moduleName, $moduleConfig);
                } elseif (is_array($moduleConfig)) {
                    if (is_string($module)) {
                        $this->config->merge($module, $moduleConfig);
                    } elseif (is_int($module)) {
                        foreach ($moduleConfig as $key => $value) {
                            if ($key instanceof IumConfigEnum) {
                                $this->config->set($key, $value);
                            } elseif (is_string($key)) {
                                $parts = explode('.', $key, 2);
                                $moduleName = $parts[0];
                                $configKey = $parts[1] ?? $key;
                                $this->config->set($key, $value);
                            }
                        }
                    }
                } elseif ($module instanceof IumConfigEnum) {
                    $this->config->set($module, $moduleConfig);
                }
            }
        }

        return $this;
    }

    public function registerModule(string $name, object $module): self
    {
        $this->modules[$name] = $module;

        return $this;
    }

    public function getModule(string $name): ?object
    {
        return $this->modules[$name] ?? null;
    }

    public function hasModule(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    public function getModules(): array
    {
        return $this->modules;
    }

    public function reset(): self
    {
        $this->config->reset();
        $this->modules = [];

        return $this;
    }
}
