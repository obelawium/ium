<?php

declare(strict_types=1);

namespace Obelaw\Ium\Exceptions;

final class AuthorizationException extends IumException
{
    public static function denied(string $ability, ?string $reason = null): self
    {
        $message = "Unauthorized domain action [{$ability}].";

        if ($reason !== null && $reason !== '') {
            $message .= " {$reason}";
        }

        return new self($message);
    }
}
