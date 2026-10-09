<?php

declare(strict_types=1);

namespace Kolai\Auth;

/**
 * Blocks replays only inside a single PHP request, so: tests only. A real store
 * must be shared across processes.
 */
final class InMemoryNonceStore implements NonceStore
{
    /** @var array<string,int> key => expiry timestamp */
    private array $claims = [];

    public function consume(string $key, int $ttlSeconds): bool
    {
        $now = time();
        if (isset($this->claims[$key]) && $this->claims[$key] > $now) {
            return false;
        }
        $this->claims[$key] = $now + $ttlSeconds;

        return true;
    }
}
