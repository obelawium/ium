<?php

declare(strict_types=1);

namespace Obelaw\Ium\Exceptions;

final class CircularModuleDependencyException extends IumException
{
    /**
     * @param list<string> $cycle
     */
    public static function forCycle(array $cycle): self
    {
        return new self('Circular module dependency detected: ' . implode(' -> ', $cycle) . '.');
    }
}
