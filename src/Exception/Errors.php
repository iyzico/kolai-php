<?php

declare(strict_types=1);

namespace Kolai\Exception;

use Kolai\Protocol\ErrorCode;

/**
 * The one place a cause is bound to its (error code, HTTP status) pair.
 *
 * Status lives in the class, cause in the code, so `catch (NotFoundException)`
 * still works while a new cause costs one line rather than a new file.
 */
final class Errors
{
    private function __construct()
    {
    }

    // --- 1xxx: platform ---------------------------------------------------

    public static function badRequest(string $message, string $code = ErrorCode::BAD_REQUEST): BadRequestException
    {
        return new BadRequestException($message, $code);
    }

    public static function notFound(string $message, string $code = ErrorCode::NOT_FOUND): NotFoundException
    {
        return new NotFoundException($message, $code);
    }

    public static function internal(string $message = 'Internal error'): InternalErrorException
    {
        return new InternalErrorException($message, ErrorCode::INTERNAL_ERROR);
    }

    public static function serviceUnavailable(string $message = 'Service unavailable'): ServiceUnavailableException
    {
        return new ServiceUnavailableException($message, ErrorCode::SERVICE_UNAVAILABLE);
    }

    public static function unauthorized(string $message = 'Unauthorized'): UnauthorizedException
    {
        return new UnauthorizedException($message, ErrorCode::UNAUTHORIZED);
    }

    /** The store software (WooCommerce / Magento / …) is missing or disabled. */
    public static function storeInactive(string $message = 'Store is not active'): ServiceUnavailableException
    {
        return new ServiceUnavailableException($message, ErrorCode::STORE_INACTIVE);
    }

    // --- 2xxx: product ----------------------------------------------------

    public static function invalidProductId(string $message = 'Invalid product id'): BadRequestException
    {
        return new BadRequestException($message, ErrorCode::INVALID_PRODUCT_ID);
    }

    public static function productNotFound(string $message = 'Product not found'): NotFoundException
    {
        return new NotFoundException($message, ErrorCode::PRODUCT_NOT_FOUND);
    }

    public static function productNotVisible(string $message = 'Product not visible'): NotFoundException
    {
        return new NotFoundException($message, ErrorCode::PRODUCT_NOT_VISIBLE);
    }

    public static function variationParentNotFound(
        string $message = 'Variation parent product not found'
    ): NotFoundException {
        return new NotFoundException($message, ErrorCode::VARIATION_PARENT_NOT_FOUND);
    }

    public static function invalidProductList(string $message = 'Invalid product list'): BadRequestException
    {
        return new BadRequestException($message, ErrorCode::INVALID_PRODUCT_LIST);
    }

    // --- 3xxx: shipping ---------------------------------------------------

    public static function invalidAddress(string $message = 'Invalid address'): BadRequestException
    {
        return new BadRequestException($message, ErrorCode::INVALID_ADDRESS);
    }

    public static function noShippingOptions(string $message = 'No shipping options available'): NotFoundException
    {
        return new NotFoundException($message, ErrorCode::NO_SHIPPING_OPTIONS);
    }

    // --- 4xxx: order ------------------------------------------------------

    public static function invalidOrderRequest(string $message = 'Invalid order request'): BadRequestException
    {
        return new BadRequestException($message, ErrorCode::INVALID_ORDER_REQUEST);
    }

    public static function invalidShipmentOption(string $message = 'Invalid shipment option'): BadRequestException
    {
        return new BadRequestException($message, ErrorCode::INVALID_SHIPMENT_OPTION);
    }

    public static function insufficientStock(string $message = 'Insufficient stock'): ConflictException
    {
        return new ConflictException($message, ErrorCode::INSUFFICIENT_STOCK);
    }

    public static function discountExceedsTotal(string $message = 'Discount exceeds order total'): BadRequestException
    {
        return new BadRequestException($message, ErrorCode::DISCOUNT_EXCEEDS_TOTAL);
    }

    // --- 5xxx: contract ---------------------------------------------------

    public static function invalidContractType(string $message = 'Invalid contract type'): BadRequestException
    {
        return new BadRequestException($message, ErrorCode::INVALID_CONTRACT_TYPE);
    }

    public static function invalidContractRequest(string $message = 'Invalid contract request'): BadRequestException
    {
        return new BadRequestException($message, ErrorCode::INVALID_CONTRACT_REQUEST);
    }

    public static function contractNotFound(string $message = 'Contract not found'): NotFoundException
    {
        return new NotFoundException($message, ErrorCode::CONTRACT_NOT_FOUND);
    }

    // --- 6xxx: review -----------------------------------------------------

    public static function invalidReviewRequest(string $message = 'Invalid review request'): BadRequestException
    {
        return new BadRequestException($message, ErrorCode::INVALID_REVIEW_REQUEST);
    }

    public static function reviewNotFound(string $message = 'Review not found'): NotFoundException
    {
        return new NotFoundException($message, ErrorCode::REVIEW_NOT_FOUND);
    }

    public static function invalidRating(string $message = 'Invalid rating value'): BadRequestException
    {
        return new BadRequestException($message, ErrorCode::INVALID_RATING);
    }

    public static function reviewsDisabled(
        string $message = 'Reviews are disabled for this product'
    ): BadRequestException {
        return new BadRequestException($message, ErrorCode::REVIEWS_DISABLED);
    }
}
