<?php

declare(strict_types=1);

namespace Obelaw\Ium\Exceptions;

final class DuplicateModuleException extends IumException
{
    public static function forName(string $name): self
    {
        return new self("Module [{$name}] is already registered.");
    }
}
