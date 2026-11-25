<?php

declare(strict_types=1);

namespace Obelaw\Ium\Exceptions;

final class MissingModuleDependencyException extends IumException
{
    public static function forModule(string $module, string $dependency): self
    {
        return new self("Module [{$module}] depends on unregistered module [{$dependency}].");
    }
}
