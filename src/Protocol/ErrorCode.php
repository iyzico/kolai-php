<?php

declare(strict_types=1);

namespace Kolai\Protocol;

/**
 * Shared by every module, so a given failure reports the same code whatever the
 * store software is.
 */
final class ErrorCode
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';

    // 1xxx: platform/plugin errors
    public const INTERNAL_ERROR      = '1000';
    public const BAD_REQUEST         = '1001';
    public const NOT_FOUND           = '1002';
    public const SERVICE_UNAVAILABLE = '1003';
    public const STORE_INACTIVE      = '1004';
    public const UNAUTHORIZED        = '1005';

    // 2xxx: product
    public const INVALID_PRODUCT_ID          = '2000';
    public const PRODUCT_NOT_FOUND           = '2001';
    public const PRODUCT_NOT_VISIBLE         = '2002';
    public const VARIATION_PARENT_NOT_FOUND  = '2003';
    public const INVALID_PRODUCT_LIST        = '2004';

    // 3xxx: shipping
    public const INVALID_ADDRESS     = '3000';
    public const NO_SHIPPING_OPTIONS = '3001';

    // 4xxx: order
    public const INVALID_ORDER_REQUEST   = '4000';
    public const INVALID_SHIPMENT_OPTION = '4001';
    public const INSUFFICIENT_STOCK      = '4002';
    public const DISCOUNT_EXCEEDS_TOTAL  = '4003';

    // 5xxx: contract
    public const INVALID_CONTRACT_TYPE    = '5000';
    public const INVALID_CONTRACT_REQUEST = '5001';
    public const CONTRACT_NOT_FOUND       = '5002';

    // 6xxx: review
    public const INVALID_REVIEW_REQUEST = '6000';
    public const REVIEW_NOT_FOUND       = '6001';
    public const INVALID_RATING         = '6003';
    public const REVIEWS_DISABLED       = '6004';

    private function __construct()
    {
    }
}
