<?php

declare(strict_types=1);

namespace Kolai\Protocol;

use Kolai\Exception\KolaiException;

/**
 * Builds the Kolai JSON response envelope — the shape every endpoint returns.
 *
 * Platform version fields (woocommerceVersion / magentoVersion / …) are given
 * once at construction, so the library never learns which store it runs inside.
 */
final class Envelope
{
    /** @var array<string,mixed> */
    private array $platformFields;

    /**
     * @param array<string,mixed> $platformFields e.g.
     *        ['woocommerceVersion' => '9.1.0', 'wordpressVersion' => '6.5.3']
     */
    public function __construct(array $platformFields = [])
    {
        $this->platformFields = $platformFields;
    }

    /**
     * @param mixed               $data
     * @param array<string,mixed> $meta Extra top-level fields, e.g. 'pagination'.
     * @return array<string,mixed>
     */
    public function success($data, array $meta = []): array
    {
        return $this->build($data, 200, null, null) + $meta;
    }

    /**
     * @return array<string,mixed>
     */
    public function fromException(KolaiException $e): array
    {
        return $this->build(null, $e->getHttpStatus(), $e->getErrorCode(), $e->getMessage());
    }

    /**
     * For a failure that is not a KolaiException. The underlying message is
     * dropped on purpose: internal errors carry file paths and SQL.
     *
     * @return array<string,mixed>
     */
    public function unexpectedError(): array
    {
        return $this->build(null, 500, ErrorCode::INTERNAL_ERROR, 'Unexpected error');
    }

    /**
     * @param mixed $data
     * @return array<string,mixed>
     */
    private function build($data, int $status, ?string $errorCode, ?string $errorMessage): array
    {
        return [
            'status'       => $status < 400 ? ErrorCode::STATUS_SUCCESS : ErrorCode::STATUS_FAILURE,
            'systemTime'   => date('c'),
            'errorCode'    => $errorCode,
            'errorMessage' => $errorMessage,
        ] + $this->platformFields + [
            'phpVersion' => PHP_VERSION,
            'data'       => $data,
        ];
    }
}
