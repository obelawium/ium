<?php

namespace Obelaw\Ium\Core\Bases;

use Illuminate\Database\Eloquent\Model;
use Obelaw\Ium\Core\Engine\GlobalConfigManager;

class ModelBase extends Model
{
    /**
     * Table prefix.
     *
     * @var string $prefix
     */
    protected string $prefix = 'ium_';

    /**
     * Optional module name for table prefixing.
     *
     * @var string|null $module
     */
    protected ?string $module = null;

    /**
     * Create a new instance of the Model.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $prefix = $this->module ? $this->prefix . $this->module : $this->prefix;

        if ($connection = GlobalConfigManager::get('obelawium.db.connection')) {
            $this->setConnection($connection);
        }

        $this->setTable($prefix . $this->getTable());
    }
}
