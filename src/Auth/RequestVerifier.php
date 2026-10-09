<?php

declare(strict_types=1);

namespace Kolai\Auth;

use Kolai\Exception\Errors;
use Kolai\Exception\UnauthorizedException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Verifies an IYZ-TP-v2 HMAC-SHA256 signed request.
 *
 * Header shape:
 *   Authorization: IYZ-TP-v2 <base64 of "clientId:…&salt:…&scope:…&signature:…">
 *   (an optional "&timestamp:<unix seconds>" pair may be present)
 *
 * Signature:
 *   hex HMAC-SHA256( salt . scope . uriPath . rawBody [. timestamp], secretKey )
 *
 * Replay defence is layered: a signed `timestamp` bounds the window to
 * MAX_TIMESTAMP_SKEW, and the salt is consumed single-use so an exact replay
 * inside that window is still rejected. Clients that do not sign a timestamp
 * yet are protected by the salt alone (bounded by NONCE_TTL) and get a
 * deprecation warning logged.
 */
final class RequestVerifier
{
    public const AUTH_PREFIX = 'IYZ-TP-v2 ';

    /** Accepted clock skew, in seconds, for a signed timestamp. */
    public const MAX_TIMESTAMP_SKEW = 300;

    /** How long a consumed salt is remembered. Bounds the replay window. */
    public const NONCE_TTL = 600;

    private string $apiKey;

    private string $secretKey;

    private NonceStore $nonceStore;

    private LoggerInterface $logger;

    public function __construct(
        string $apiKey,
        string $secretKey,
        NonceStore $nonceStore,
        ?LoggerInterface $logger = null
    ) {
        $this->apiKey     = $apiKey;
        $this->secretKey  = $secretKey;
        $this->nonceStore = $nonceStore;
        $this->logger     = $logger ?? new NullLogger();
    }

    /**
     * @return string The verified scope.
     * @throws UnauthorizedException Message is safe to return to the caller: it
     *         never contains a key, salt or signature.
     */
    public function verify(SignedRequest $request, string $expectedScope): string
    {
        $params = $this->parseHeader($request->authorizationHeader());

        // Fail closed. An empty stored secret would let anyone forge a valid
        // HMAC with the known empty key; an empty client id can never match.
        if ($this->apiKey === '' || $this->secretKey === '') {
            $this->logger->warning('kolai.auth: server credentials not configured', [
                'apiKeyEmpty'    => $this->apiKey === '',
                'secretKeyEmpty' => $this->secretKey === '',
            ]);
            throw Errors::unauthorized('Server credentials not configured');
        }

        // Constant-time compare; never log the received value.
        if (!hash_equals($this->apiKey, $params['clientId'])) {
            $this->logger->warning('kolai.auth: invalid client credentials', [
                'receivedLength' => strlen($params['clientId']),
            ]);
            throw Errors::unauthorized('Invalid client credentials');
        }

        if (!hash_equals($expectedScope, $params['scope'])) {
            $this->logger->warning('kolai.auth: invalid scope', [
                'expected' => $expectedScope,
                'received' => $params['scope'],
            ]);
            throw Errors::unauthorized('Invalid scope');
        }

        $timestamp = $params['timestamp'] ?? '';
        $payload   = $params['salt'] . $params['scope'] . $request->uriPath() . $request->body() . $timestamp;
        $expected  = hash_hmac('sha256', $payload, $this->secretKey);

        if (!hash_equals($expected, $params['signature'])) {
            // Log what the signature was computed OVER, never the signatures
            // themselves or the secret — that would defeat the purpose.
            $this->logger->warning('kolai.auth: invalid signature', [
                'uriPath'      => $request->uriPath(),
                'bodyLength'   => strlen($request->body()),
                'scope'        => $params['scope'],
                'hasTimestamp' => $timestamp !== '',
            ]);
            throw Errors::unauthorized('Invalid signature');
        }

        // Only now is the timestamp trustworthy: it is covered by the HMAC.
        if ($timestamp !== '') {
            $this->assertFreshTimestamp($timestamp);
        } else {
            $this->logger->warning('kolai.auth: request signed without a timestamp (deprecated)', [
                'scope'   => $params['scope'],
                'uriPath' => $request->uriPath(),
            ]);
        }

        // Consume the salt only AFTER the signature checks out, so unsigned
        // traffic can never poison the nonce store with salts of its choosing.
        if (!$this->nonceStore->consume($params['clientId'] . '|' . $params['salt'], self::NONCE_TTL)) {
            $this->logger->warning('kolai.auth: replayed request rejected', [
                'scope'   => $params['scope'],
                'uriPath' => $request->uriPath(),
            ]);
            throw Errors::unauthorized('Replayed request rejected');
        }

        $this->logger->info('kolai.auth: validation passed', [
            'scope'   => $params['scope'],
            'uriPath' => $request->uriPath(),
        ]);

        return $params['scope'];
    }

    /**
     * @return array<string,string>
     * @throws UnauthorizedException
     */
    private function parseHeader(string $header): array
    {
        if ($header === '' || strpos($header, self::AUTH_PREFIX) !== 0) {
            $this->logger->warning('kolai.auth: missing or invalid authorization header', [
                'headerPresent' => $header !== '',
            ]);
            throw Errors::unauthorized('Missing or invalid authorization header');
        }

        // Repeated Authorization headers get joined with ", " (RFC 7230 3.2.2).
        // Usually a client that both runs a signing script AND has its Auth tab
        // set to Bearer/Basic. The base64 alphabet never contains ", ", so this
        // can only mean two headers arrived.
        if (strpos($header, ', ') !== false || stripos($header, 'Bearer ') !== false) {
            $this->logger->warning('kolai.auth: duplicate authorization headers detected', [
                'parts'        => substr_count($header, ', ') + 1,
                'headerLength' => strlen($header),
            ]);
            throw Errors::unauthorized(
                'Duplicate Authorization headers detected. Send only the IYZ-TP-v2 header.'
            );
        }

        $encoded = substr($header, strlen(self::AUTH_PREFIX));
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            $this->logger->warning('kolai.auth: invalid authorization encoding', [
                'encodedLength' => strlen($encoded),
            ]);
            throw Errors::unauthorized('Invalid authorization encoding');
        }

        $params = [];
        foreach (explode('&', $decoded) as $pair) {
            $separator = strpos($pair, ':');
            if ($separator === false) {
                // Do not echo the malformed pair back: it is attacker-controlled.
                $this->logger->warning('kolai.auth: malformed authorization payload');
                throw Errors::unauthorized('Malformed authorization payload');
            }
            $params[substr($pair, 0, $separator)] = substr($pair, $separator + 1);
        }

        foreach (['clientId', 'salt', 'scope', 'signature'] as $key) {
            if (!isset($params[$key]) || $params[$key] === '') {
                $this->logger->warning('kolai.auth: missing authorization parameter', [
                    'parameter'   => $key,
                    'presentKeys' => array_keys($params),
                ]);
                throw Errors::unauthorized('Missing authorization parameter: ' . $key);
            }
        }

        return $params;
    }

    /**
     * @throws UnauthorizedException
     */
    private function assertFreshTimestamp(string $timestamp): void
    {
        if (!is_numeric($timestamp)) {
            throw Errors::unauthorized('Invalid timestamp');
        }

        $skew = abs(time() - (int) $timestamp);
        if ($skew > self::MAX_TIMESTAMP_SKEW) {
            $this->logger->warning('kolai.auth: stale request rejected', [
                'skewSeconds' => $skew,
                'maxSkew'     => self::MAX_TIMESTAMP_SKEW,
            ]);
            throw Errors::unauthorized('Request timestamp outside allowed window');
        }
    }

    /**
     * Only the Kolai backend signs in production. This exists so module test
     * suites and sandbox tooling get a valid header without reimplementing the
     * scheme and drifting from it.
     */
    public static function sign(
        string $apiKey,
        string $secretKey,
        string $scope,
        string $uriPath,
        string $body = '',
        ?string $salt = null,
        ?int $timestamp = null
    ): string {
        $salt      = $salt ?? bin2hex(random_bytes(16));
        $timestamp = $timestamp ?? time();
        $signature = hash_hmac('sha256', $salt . $scope . $uriPath . $body . $timestamp, $secretKey);

        $payload = sprintf(
            'clientId:%s&salt:%s&scope:%s&signature:%s&timestamp:%d',
            $apiKey,
            $salt,
            $scope,
            $signature,
            $timestamp
        );

        return self::AUTH_PREFIX . base64_encode($payload);
    }
}
