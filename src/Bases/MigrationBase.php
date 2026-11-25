<?php

declare(strict_types=1);

namespace Obelaw\Ium\Bases;

use Illuminate\Database\Migrations\Migration;

abstract class MigrationBase extends Migration
{
    /**
     * Normalized table prefix (`ium_` or `ium_<module>_`).
     */
    protected string $prefix = 'ium_';

    /**
     * Optional module slug for table prefixing (e.g. 'accounting' or 'accounting_').
     */
    protected ?string $module = null;

    /**
     * Create a new instance of the migration.
     */
    public function __construct()
    {
        $this->prefix = $this->resolveTablePrefix();
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
