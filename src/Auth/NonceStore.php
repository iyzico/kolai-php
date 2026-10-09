<?php

declare(strict_types=1);

namespace Kolai\Auth;

/**
 * Single-use store for signed request salts — the replay defence, and the only
 * part of authentication each platform must supply itself.
 *
 * Implementations MUST be atomic across concurrent processes. A check-then-set
 * lets two copies of the same replayed request both pass.
 */
interface NonceStore
{
    /**
     * Claim $key. True on first claim, false if claimed and not yet expired.
     *
     * @param string $key Opaque; safe to hash into a cache key.
     */
    public function consume(string $key, int $ttlSeconds): bool;
}
