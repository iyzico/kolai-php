<?php

declare(strict_types=1);

namespace Kolai\Auth;

/**
 * Request scopes, matching the Kolai backend's enum. Each endpoint demands
 * exactly the one it serves, so a signature minted to read products cannot
 * create an order.
 */
final class Scope
{
    public const RETRIEVE_PRODUCT               = 'RETRIEVE_PRODUCT';
    public const RETRIEVE_PRODUCTS              = 'RETRIEVE_PRODUCTS';
    public const RETRIEVE_PRODUCT_WITH_VARIANTS = 'RETRIEVE_PRODUCT_WITH_VARIANTS';
    public const RETRIEVE_SHIPMENT_OPTIONS      = 'RETRIEVE_SHIPMENT_OPTIONS';
    public const CREATE_ORDER                   = 'CREATE_ORDER';
    public const UPDATE_ORDER_STATUS            = 'UPDATE_ORDER_STATUS';
    public const RETRIEVE_ORDER                 = 'RETRIEVE_ORDER';
    public const RETRIEVE_ORDER_TYPES           = 'RETRIEVE_ORDER_TYPES';
    public const RETRIEVE_CONTRACT              = 'RETRIEVE_CONTRACT';
    public const RETRIEVE_REVIEWS               = 'RETRIEVE_REVIEWS';
    public const RETRIEVE_REVIEW                = 'RETRIEVE_REVIEW';

    private function __construct()
    {
    }
}
