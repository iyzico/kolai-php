<?php

declare(strict_types=1);

namespace Kolai\Exception;

/**
 * HTTP 404 failure.
 */
class NotFoundException extends KolaiException
{
    public function __construct(string $message, string $errorCode)
    {
        parent::__construct($message, $errorCode, 404);
    }
}
