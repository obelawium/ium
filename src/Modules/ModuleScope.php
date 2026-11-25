<?php

declare(strict_types=1);

namespace Obelaw\Ium\Modules;

enum ModuleScope: string
{
    case Central = 'central';
    case Tenant = 'tenant';
    case Both = 'both';

    public function runsInCentral(): bool
    {
        return $this === self::Central || $this === self::Both;
    }

    public function runsInTenant(): bool
    {
        return $this === self::Tenant || $this === self::Both;
    }
}
