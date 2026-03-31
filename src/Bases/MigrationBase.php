<?php

namespace Obelaw\Ium\Bases;

use Illuminate\Database\Migrations\Migration;

abstract class MigrationBase extends Migration
{
    /**
     * Table prefix.
     *
     * @var string $prefix
     */
    protected string $prefix = 'ium_';

    /**
     * Table postfix.
     *
     * @var string|null $module
     */
    protected ?string $module = null;

    /**
     * Create a new instance of the migration.
     */
    public function __construct()
    {
        $this->prefix = $this->module ? $this->prefix . $this->module : $this->prefix;
    }
}
