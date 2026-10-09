<?php

declare(strict_types=1);

namespace Kolai\Auth;

/**
 * The three pieces of a request the signature covers. Both easy mistakes here
 * surface as the same "Invalid signature":
 *
 *  - $uriPath is the RAW path, no query string, as the signer saw it — not the
 *    framework's routed path, not a rewritten one. One character fails the HMAC.
 *  - $body is the RAW bytes. json_encode(json_decode($body)) reorders keys and
 *    changes spacing, which also fails it. Empty string for GET.
 */
final class SignedRequest
{
    private string $authorizationHeader;

    private string $uriPath;

    private string $body;

    public function __construct(string $authorizationHeader, string $uriPath, string $body = '')
    {
        $this->authorizationHeader = $authorizationHeader;
        $this->uriPath             = $uriPath;
        $this->body                = $body;
    }

    /** Drops the query string for you. */
    public static function fromRequestUri(string $authorizationHeader, string $requestUri, string $body = ''): self
    {
        $queryAt = strpos($requestUri, '?');
        $path    = $queryAt === false ? $requestUri : substr($requestUri, 0, $queryAt);

        return new self($authorizationHeader, $path, $body);
    }

    public function authorizationHeader(): string
    {
        return $this->authorizationHeader;
    }

    public function uriPath(): string
    {
        return $this->uriPath;
    }

    public function body(): string
    {
        return $this->body;
    }
}
