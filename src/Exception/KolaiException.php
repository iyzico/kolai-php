<?php

declare(strict_types=1);

namespace Kolai\Exception;

use RuntimeException;

/**
 * Base for every Kolai domain failure. The class carries the HTTP status, the
 * error code names the cause — two things that are easy to conflate.
 *
 * Build these through {@see Errors}, not by hand.
 */
abstract class KolaiException extends RuntimeException
{
    private string $errorCode;

    private int $httpStatus;

    public function __construct(string $message, string $errorCode, int $httpStatus)
    {
        // Keep the numeric Kolai code out of Exception::getCode(): callers that
        // forward getCode() to a response would emit "2001" as an HTTP status.
        parent::__construct($message);
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
