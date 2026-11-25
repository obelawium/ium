<?php

declare(strict_types=1);

namespace Obelaw\Ium\Bases;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Obelaw\Ium\Contracts\TenantContext;
use Obelaw\Ium\Engine\GlobalConfigManager;

class ModelBase extends Model
{
    /**
     * Base table prefix.
     */
    protected string $prefix = 'ium_';

    /**
     * Optional module slug for table prefixing (e.g. 'accounting' or 'accounting_').
     */
    protected ?string $module = null;

    /**
     * Create a new instance of the Model.
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $prefix = $this->resolveTablePrefix();
        $table = $this->getTable();

        if (! str_starts_with($table, $prefix)) {
            $this->setTable($prefix . $table);
        }
    }

    /**
     * Dynamically resolve the database connection name from:
     * 1. Explicit `$this->connection` set on the model.
     * 2. Active `TenantContext::connectionName()` bound in the container.
     * 3. `GlobalConfigManager::get('obelawium.db.connection')`.
     * 4. Laravel's default database connection.
     */
    public function getConnectionName(): ?string
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $container = Container::getInstance();

        if ($container->bound(TenantContext::class)) {
            /** @var TenantContext $tenantContext */
            $tenantContext = $container->make(TenantContext::class);
            $tenantConnection = $tenantContext->connectionName();

            if ($tenantConnection !== null && $tenantConnection !== '') {
                return $tenantConnection;
            }
        }

        $globalConnection = GlobalConfigManager::get('obelawium.db.connection');

        if (is_string($globalConnection) && $globalConnection !== '') {
            return $globalConnection;
        }

        return parent::getConnectionName();
    }

    /**
     * Resolve the normalized table prefix (`ium_` or `ium_<module>_`).
     */
    protected function resolveTablePrefix(): string
    {
        $base = rtrim($this->prefix, '_') . '_';

        if ($this->module === null || trim($this->module, '_') === '') {
            return $base;
        }

        return $base . trim($this->module, '_') . '_';
    }
}
