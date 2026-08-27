<?php

namespace Obelaw\Ium\Abstracts;

use Obelaw\Ium\Engine\ObelawConfigManager;

class Domain
{
    public function __construct(private ObelawConfigManager $config) {}
}
