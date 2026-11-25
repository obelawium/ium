<?php

declare(strict_types=1);

namespace Obelaw\Ium\Exceptions;

final class ModuleNotFoundException extends IumException
{
    public static function forName(string $name): self
    {
        return new self("Module [{$name}] is not registered.");
    }
}
