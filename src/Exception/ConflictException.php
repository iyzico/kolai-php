<?php

declare(strict_types=1);

namespace Kolai\Exception;

/**
 * HTTP 409 failure.
 */
class ConflictException extends KolaiException
{
    public function __construct(string $message, string $errorCode)
    {
        parent::__construct($message, $errorCode, 409);
    }
}
