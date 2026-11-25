<?php

declare(strict_types=1);

namespace Obelaw\Ium\Models;

use Obelaw\Ium\Bases\ModelBase;

/**
 * @property int $id
 * @property string $name
 * @property int $current_value
 */
final class SequenceCounter extends ModelBase
{
    protected $table = 'sequences';

    protected $fillable = [
        'name',
        'current_value',
    ];

    protected $casts = [
        'current_value' => 'integer',
    ];
}
