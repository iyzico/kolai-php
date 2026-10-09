<?php

/**
 * Runnable self-check: php tests/selfcheck.php
 *
 * Covers the parts where a silent mistake costs real money or leaks real data —
 * refund allocation, signature verification, address normalization, invoice
 * validation and log redaction. Not a substitute for integration tests inside
 * each platform module; this is the net under the pure logic.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kolai\Address\AddressNormalizer;
use Kolai\Address\TurkeyProvinces;
use Kolai\Auth\InMemoryNonceStore;
use Kolai\Auth\RequestVerifier;
use Kolai\Auth\Scope;
use Kolai\Auth\SignedRequest;
use Kolai\Contract\ContractTemplates;
use Kolai\Exception\BadRequestException;
use Kolai\Exception\NotFoundException;
use Kolai\Exception\UnauthorizedException;
use Kolai\Log\Redactor;
use Kolai\Mapper\ProductMapper;
use Kolai\Mapper\ReviewMapper;
use Kolai\Order\OrderMath;
use Kolai\Order\OrderRequestValidator;
use Kolai\Payment\IyzicoCredentials;
use Kolai\Payment\RefundAllocator;
use Kolai\Protocol\Envelope;
use Kolai\Protocol\ErrorCode;
use Kolai\Support\Str;

$checks = 0;

function check(string $what, bool $ok): void
{
    global $checks;
    $checks++;
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$what}\n");
        exit(1);
    }
}

/** @return string Exception class name, or '' when nothing was thrown. */
function throws(callable $fn): string
{
    try {
        $fn();
    } catch (Throwable $e) {
        return get_class($e);
    }

    return '';
}

// ---------------------------------------------------------------- refunds ---
// Three items, 50 each. A 120 refund must fill the first two and take 20 from
// the third — never over-refund a single transaction.
$transactions = [
    ['paymentTransactionId' => 'tx1', 'paidPrice' => 50.00],
    ['paymentTransactionId' => 'tx2', 'paidPrice' => 50.00],
    ['paymentTransactionId' => 'tx3', 'paidPrice' => 50.00],
];

$plan = RefundAllocator::plan($transactions, [], 120.00);
check('full allocation leaves nothing unallocated', abs($plan['unallocated']) < 0.005);
check('allocation spans three transactions', count($plan['steps']) === 3);
check('allocation amounts 50/50/20', array_column($plan['steps'], 'amount') === [50.0, 50.0, 20.0]);

// A retry after a partial failure must skip what already moved.
$ledger = RefundAllocator::record([], 'tx1', 50.00);
$retry  = RefundAllocator::plan($transactions, $ledger, 70.00);
check('retry skips the settled transaction', $retry['steps'][0]['paymentTransactionId'] === 'tx2');
check('retry allocates 50 + 20', array_column($retry['steps'], 'amount') === [50.0, 20.0]);
check('retry fully allocated', abs($retry['unallocated']) < 0.005);

// Over-refunding must be refused, not silently clipped: a partial plan would
// pay back less than the store already recorded as refunded.
$over = RefundAllocator::plan($transactions, [], 200.00);
check('over-refund reports the shortfall', abs($over['unallocated'] - 50.00) < 0.005);
check('refundable total respects the ledger', abs(RefundAllocator::refundableTotal($transactions, $ledger) - 100.00) < 0.005);

// A transaction with no id is unusable; skipping it must not consume budget.
$plan = RefundAllocator::plan([['paidPrice' => 10.0], ['paymentTransactionId' => 'tx9', 'paidPrice' => 10.0]], [], 10.0);
check('id-less transaction is skipped', count($plan['steps']) === 1 && $plan['steps'][0]['paymentTransactionId'] === 'tx9');

// A negative or zero amount used to produce no steps and `unallocated` <= 0,
// which passes the caller's abort check and silently refunds nothing.
check(
    'non-positive refund amount is rejected',
    throws(static fn () => RefundAllocator::plan($transactions, [], -50.0)) === BadRequestException::class
    && throws(static fn () => RefundAllocator::plan($transactions, [], 0.0)) === BadRequestException::class
);

$opId = RefundAllocator::newOperationId();
check('operation id is a uuid', (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $opId));
check('operation ids are unique', $opId !== RefundAllocator::newOperationId());
check(
    'conversation id carries order, tx and attempt',
    RefundAllocator::conversationId('42', 'tx1', 'abcdef1234') === 'order-42-tx-tx1-abcdef12'
);

// ------------------------------------------------------------------- auth ---
$apiKey = 'client-key';
$secret = 'super-secret';
$path   = '/wp-json/kolai/v1/orders';
$body   = '{"buyer":{"email":"a@b.c"}}';

$verifier = new RequestVerifier($apiKey, $secret, new InMemoryNonceStore());
$header   = RequestVerifier::sign($apiKey, $secret, Scope::CREATE_ORDER, $path, $body, 'salt-1');

check(
    'valid signature passes',
    $verifier->verify(new SignedRequest($header, $path, $body), Scope::CREATE_ORDER) === Scope::CREATE_ORDER
);

// Replay: same header twice. The salt is single-use.
check(
    'replayed request is rejected',
    throws(static fn () => $verifier->verify(new SignedRequest($header, $path, $body), Scope::CREATE_ORDER))
        === UnauthorizedException::class
);

// Each of these must fail. Any one passing is a signature forgery path.
$header2 = RequestVerifier::sign($apiKey, $secret, Scope::CREATE_ORDER, $path, $body, 'salt-2');
$cases = [
    'tampered body'        => new SignedRequest($header2, $path, $body . ' '),
    'tampered path'        => new SignedRequest($header2, $path . '/9', $body),
    'missing header'       => new SignedRequest('', $path, $body),
    'wrong prefix'         => new SignedRequest('Bearer ' . base64_encode('x'), $path, $body),
    'duplicate header'     => new SignedRequest($header2 . ', ' . $header2, $path, $body),
    'non-base64 payload'   => new SignedRequest(RequestVerifier::AUTH_PREFIX . '!!!not base64!!!', $path, $body),
    'malformed pairs'      => new SignedRequest(RequestVerifier::AUTH_PREFIX . base64_encode('clientId-no-colon'), $path, $body),
];
foreach ($cases as $label => $request) {
    check(
        $label . ' is rejected',
        throws(static fn () => $verifier->verify($request, Scope::CREATE_ORDER)) === UnauthorizedException::class
    );
}

// Scope confusion: a token signed to read products must not create an order.
$productHeader = RequestVerifier::sign($apiKey, $secret, Scope::RETRIEVE_PRODUCTS, $path, $body, 'salt-3');
check(
    'wrong scope is rejected',
    throws(static fn () => $verifier->verify(new SignedRequest($productHeader, $path, $body), Scope::CREATE_ORDER))
        === UnauthorizedException::class
);

// Stale timestamp, correctly signed.
$stale = RequestVerifier::sign($apiKey, $secret, Scope::CREATE_ORDER, $path, $body, 'salt-4', time() - 3600);
check(
    'stale timestamp is rejected',
    throws(static fn () => $verifier->verify(new SignedRequest($stale, $path, $body), Scope::CREATE_ORDER))
        === UnauthorizedException::class
);

// Fail closed: unconfigured credentials must never accept a request, even one
// signed with the empty secret the server actually holds.
$unconfigured = new RequestVerifier('', '', new InMemoryNonceStore());
$forged       = RequestVerifier::sign('', '', Scope::CREATE_ORDER, $path, $body, 'salt-5');
check(
    'empty credentials fail closed',
    throws(static fn () => $unconfigured->verify(new SignedRequest($forged, $path, $body), Scope::CREATE_ORDER))
        === UnauthorizedException::class
);

// A GET has no body; the signature must still verify over the empty string.
$getHeader = RequestVerifier::sign($apiKey, $secret, Scope::RETRIEVE_PRODUCTS, '/kolai/v1/products', '', 'salt-6');
check(
    'empty body verifies',
    $verifier->verify(
        SignedRequest::fromRequestUri($getHeader, '/kolai/v1/products?page=2&per_page=50'),
        Scope::RETRIEVE_PRODUCTS
    ) === Scope::RETRIEVE_PRODUCTS
);

// ---------------------------------------------------------------- address ---
$full = AddressNormalizer::destination([
    'countryId'   => 'TR',
    'cityId'      => '6',
    'town'        => 'Cankaya',
    'addressLine' => 'Ataturk Bulvari 1',
    'postcode'    => '06100',
]);
check('TR plate code is padded to a state code', $full['state'] === 'TR06');
check('district becomes city', $full['city'] === 'Cankaya');
check('address line is carried', $full['addressLine1'] === 'Ataturk Bulvari 1');

// Legacy key spellings, newest first.
check(
    'district key falls back to districtId',
    AddressNormalizer::destination(['countryId' => 'TR', 'cityId' => '34', 'districtId' => 'Kadikoy'])['city'] === 'Kadikoy'
);
check(
    'town wins over district',
    AddressNormalizer::destination([
        'countryId' => 'TR', 'cityId' => '34', 'town' => 'Besiktas', 'district' => 'Kadikoy',
    ])['city'] === 'Besiktas'
);

// No district at all: the province name beats a blank city.
check(
    'missing district falls back to the province name',
    AddressNormalizer::destination(['countryId' => 'TR', 'cityId' => '34'])['city'] === 'Istanbul'
);
check(
    'empty-string district is treated as missing',
    AddressNormalizer::destination(['countryId' => 'TR', 'cityId' => '35', 'town' => ''])['city'] === 'Izmir'
);

// Non-TR must pass through untouched — no plate-code padding.
check(
    'non-TR state is not rewritten',
    AddressNormalizer::destination(['countryId' => 'DE', 'cityId' => 'BY', 'town' => 'Munchen'])['state'] === 'BY'
);

check('phone strips +90', AddressNormalizer::phone('+90 535 540 11 22') === '5355401122');
check('phone strips leading 0', AddressNormalizer::phone('0535 540 11 22') === '5355401122');
check('bare 10 digits pass through', AddressNormalizer::phone('5355401122') === '5355401122');
// A foreign number must not be mangled into something undialable.
check('foreign number is left alone', AddressNormalizer::phone('+49 151 23456789') === '+49 151 23456789');

check(
    'missing cityId is rejected',
    throws(static fn () => AddressNormalizer::destination(['countryId' => 'TR'])) === BadRequestException::class
);

$orderAddress = AddressNormalizer::orderAddress(
    ['countryId' => 'TR', 'cityId' => '34', 'town' => 'Kadikoy', 'companyName' => 'Acme Ltd'],
    ['firstName' => 'Ayse', 'lastName' => 'Yilmaz', 'email' => 'ayse@example.com', 'phone' => '+905355401122'],
    true
);
check('billing address carries contact', $orderAddress['email'] === 'ayse@example.com' && $orderAddress['phone'] === '5355401122');
check('company name is carried', $orderAddress['company'] === 'Acme Ltd');

// Contact details must not be duplicated onto the shipping address.
$shipAddress = AddressNormalizer::orderAddress(
    ['countryId' => 'TR', 'cityId' => '34', 'town' => 'Kadikoy'],
    ['firstName' => 'Ayse', 'email' => 'ayse@example.com'],
    false
);
check('shipping address omits contact', !isset($shipAddress['email']) && !isset($shipAddress['phone']));

// '99' is two digits and still not a province; passing it through would yield a
// state code ('TR99') that no shipping zone matches.
check('unknown plate code is rejected', TurkeyProvinces::plateCode('99') === '');
check('plate code accepts bare, padded and prefixed forms', TurkeyProvinces::plateCode('6') === '06'
    && TurkeyProvinces::plateCode('06') === '06'
    && TurkeyProvinces::plateCode('TR06') === '06');
check('province name resolves', TurkeyProvinces::name('34') === 'Istanbul');

// Str::clean strips control characters and the Unicode line/paragraph separators.
check('control characters are stripped', Str::clean("a\x07b") === 'a b');
check('unicode line separator is stripped', Str::clean("a\u{2028}b") === 'a b');
check('turkish characters survive', Str::clean(' Çağrı  Şahin ') === 'Çağrı Şahin');

// ------------------------------------------------------------------ order ---
$payload = [
    'buyer'            => ['email' => 'a@b.c', 'firstName' => 'A'],
    'billingAddress'   => ['countryId' => 'TR', 'cityId' => '34', 'invoiceType' => 'personal', 'taxId' => '12345678901'],
    'shippingAddress'  => ['countryId' => 'TR', 'cityId' => '34'],
    'products'         => [['productId' => 10, 'quantity' => 2], 11],
    'shipmentOptionId' => 'flat_rate:1',
];
$lines = OrderRequestValidator::validate($payload);
check('line objects and bare ids both parse', count($lines) === 2);
check('quantity is honored', $lines[0] === ['productId' => 10, 'variationId' => 0, 'quantity' => 2]);
check('bare id defaults to one unit', $lines[1] === ['productId' => 11, 'variationId' => 0, 'quantity' => 1]);

check(
    'missing buyer email is rejected',
    throws(static fn () => OrderRequestValidator::validate(['products' => [1]])) === BadRequestException::class
);
check(
    'missing shipmentOptionId is rejected',
    throws(static function () use ($payload) {
        unset($payload['shipmentOptionId']);
        OrderRequestValidator::validate($payload);
    }) === BadRequestException::class
);
check(
    'zero quantity is rejected',
    throws(static fn () => OrderRequestValidator::lines([['productId' => 1, 'quantity' => 0]])) === BadRequestException::class
);
check(
    'empty product list is rejected',
    throws(static fn () => OrderRequestValidator::lines([])) === BadRequestException::class
);

// TCKN is 11 digits, VKN is 10. Swapping them produces an invoice the tax
// office rejects, so both lengths are checked.
check(
    '10-digit TCKN is rejected',
    throws(static fn () => OrderRequestValidator::assertInvoice(['invoiceType' => 'personal', 'taxId' => '1234567890']))
        === BadRequestException::class
);
check(
    '11-digit VKN is rejected',
    throws(static fn () => OrderRequestValidator::assertInvoice(['invoiceType' => 'company', 'taxId' => '12345678901']))
        === BadRequestException::class
);
check(
    'unknown invoice type is rejected',
    throws(static fn () => OrderRequestValidator::assertInvoice(['invoiceType' => 'foundation']))
        === BadRequestException::class
);
check('absent invoiceType is allowed', throws(static fn () => OrderRequestValidator::assertInvoice([])) === '');
check(
    'invoiceType without taxId is allowed',
    throws(static fn () => OrderRequestValidator::assertInvoice(['invoiceType' => 'company'])) === ''
);

// ------------------------------------------------------------- order math ---
check('valid discount is accepted', OrderMath::discount(100.0, '25.50') === 25.50);
check(
    'discount above total is rejected',
    throws(static fn () => OrderMath::discount(100.0, 100.01)) === BadRequestException::class
);
check(
    'zero discount is rejected',
    throws(static fn () => OrderMath::discount(100.0, 0)) === BadRequestException::class
);
check('discount equal to total is allowed', OrderMath::discount(100.0, 100.0) === 100.0);

// paidPrice above the order total is installment interest.
$adj = OrderMath::installmentAdjustment(['paidPrice' => 110.0, 'installment' => 3], 100.0);
check('interest is detected', $adj['kind'] === 'interest' && abs($adj['amount'] - 10.0) < 0.005);
check('installment count is carried', $adj['installment'] === 3);

$adj = OrderMath::installmentAdjustment(['paidPrice' => 95.0], 100.0);
check('negative adjustment is a discount', $adj['kind'] === 'discount' && abs($adj['amount'] + 5.0) < 0.005);

// Repeating the same PATCH must converge: with the fee stripped, the base total
// already equals paidPrice, so there is nothing left to add.
check('equal paidPrice adds nothing', OrderMath::installmentAdjustment(['paidPrice' => 100.0], 100.0)['kind'] === 'none');
check('absent paidPrice adds nothing', OrderMath::installmentAdjustment(['installment' => 6], 100.0)['kind'] === 'none');
// A very large discount is legitimate and lands the total on paidPrice, which
// is positive by definition — so it must be applied, not refused.
$deep = OrderMath::installmentAdjustment(['paidPrice' => 1.0], 500.0);
check('large discount is applied, not refused', $deep['kind'] === 'discount' && abs($deep['amount'] + 499.0) < 0.005);
check('non-positive paidPrice adds nothing', OrderMath::installmentAdjustment(['paidPrice' => 0], 500.0)['kind'] === 'none');
check('non-numeric paidPrice adds nothing', OrderMath::installmentAdjustment(['paidPrice' => 'abc'], 500.0)['kind'] === 'none');
check('sub-kurus drift is not an adjustment', OrderMath::installmentAdjustment(['paidPrice' => 100.001], 100.0)['kind'] === 'none');

// ---------------------------------------------------------------- redactor ---
$redacted = Redactor::payload([
    'buyer'          => ['email' => 'a@b.c', 'firstName' => 'Ayse'],
    'billingAddress' => ['addressLine' => 'Ataturk Bulvari 1'],
    'buyerEmail'     => 'a@b.c',
    'shipmentOptionId' => 'flat_rate:1',
    'products'       => [['productId' => 10, 'quantity' => 2]],
]);
check('buyer container is dropped whole', $redacted['buyer'] === Redactor::MARKER);
check('address container is dropped whole', $redacted['billingAddress'] === Redactor::MARKER);
check('scalar PII key is masked', $redacted['buyerEmail'] === Redactor::MARKER);
check('non-sensitive fields survive', $redacted['shipmentOptionId'] === 'flat_rate:1');
check('product shape survives', $redacted['products'][0]['quantity'] === 2);

$encoded = json_encode($redacted);
check('no e-mail leaks through', strpos((string) $encoded, 'a@b.c') === false);
check('no street address leaks through', strpos((string) $encoded, 'Ataturk') === false);

// 'company' must not be caught by a sloppy fragment match.
check('benign keys are not redacted', Redactor::isSensitiveKey('company') === false);
check('benign keys are not redacted (quantity)', Redactor::isSensitiveKey('quantity') === false);
check('tax office is redacted', Redactor::isSensitiveKey('taxOffice') === true);

$huge = Redactor::payload(['notes' => array_fill(0, 500, 'xxxxxxxxxxxxxxxxxxxx')]);
check('oversized payload is truncated', isset($huge['_redactedSummary']));
check('truncation respects the cap', strlen($huge['_redactedSummary']) < Redactor::MAX_BODY_BYTES + 40);

// --------------------------------------------------------------- mappers ---
$mapper = new ProductMapper('TRY');
$mapped = $mapper->map([
    'id'             => 10,
    'name'           => 'Tisort',
    'price'          => 118,
    'sale_price'     => 99.5,
    'included_tax'   => true,
    'tax_price'      => 18.0,
    'tax_percentage' => 18,
    'sku'            => 'TS-1',
    'in_stock'       => true,
    'dimensions'     => ['length' => 10, 'width' => null, 'height' => 2],
    'image'          => ['url' => 'https://x/1.jpg'],
    'gallery'        => [['url' => 'https://x/2.jpg'], ['noturl' => 'x']],
    'variations'     => [
        ['id' => 11, 'price' => 118, 'in_stock' => true],
        ['_truncated' => true, '_max' => 100],
    ],
]);
check('id becomes a string', $mapped['id'] === '10');
check('name becomes title', $mapped['title'] === 'Tisort');
check('prices are 2-decimal strings', $mapped['price'] === '118.00' && $mapped['salePrice'] === '99.50');
check('tax breakdown is carried', $mapped['includedTax'] === true && $mapped['taxPrice'] === '18.00');
check('currency is injected', $mapped['currency'] === 'TRY');
check('sku maps to gtin and mpn', $mapped['gtin'] === 'TS-1' && $mapped['mpn'] === 'TS-1');
check('null dimensions are omitted', isset($mapped['productLength']) && !isset($mapped['productWidth']));
check('gallery entries without a url are skipped', $mapped['additionalImageLinks'] === ['https://x/2.jpg']);
// Truncation must be visible, not disguised as an empty variation.
check('truncation is surfaced', $mapped['variationsTruncated'] === true && $mapped['variationsMax'] === 100);
check('sentinel is not mapped as a variation', count($mapped['variations']) === 1);
check('empty product maps to null', $mapper->map([]) === null);

// A half-specified sale window tells the consumer nothing: omit it.
$noWindow = $mapper->map(['id' => 1, 'date_on_sale_from' => '2026-01-01T00:00:00Z']);
check('half-open sale window is omitted', !isset($noWindow['salePriceEffectiveDate']));

$reviews = (new ReviewMapper())->mapMany([
    ['id' => 1, 'product_id' => 10, 'rating' => 5, 'verified_buyer' => 1],
    ['id' => 2, 'product_id' => 10, 'rating' => null, 'parent_id' => 1],
    'not-an-array',
]);
check('reviews map to camelCase', $reviews[0]['productId'] === '10' && $reviews[0]['verifiedBuyer'] === true);
// A reply carries no rating; null must survive rather than vanish.
check('null rating survives as null', array_key_exists('rating', $reviews[1]) && $reviews[1]['rating'] === null);
check('reply parent is carried', $reviews[1]['parentId'] === 1);
check('non-array rows are skipped', count($reviews) === 2);

// -------------------------------------------------------------- envelope ---
$envelope = new Envelope(['woocommerceVersion' => '9.1.0', 'wordpressVersion' => '6.5.3']);
$ok = $envelope->success(['x' => 1], ['pagination' => ['page' => 1]]);
check('success status', $ok['status'] === ErrorCode::STATUS_SUCCESS);
check('platform fields are merged', $ok['woocommerceVersion'] === '9.1.0');
check('meta is merged alongside data', $ok['pagination']['page'] === 1);
check('errors are null on success', $ok['errorCode'] === null && $ok['errorMessage'] === null);
check('envelope fills in phpVersion itself', $ok['phpVersion'] === PHP_VERSION);

// Nothing about the field NAMES is hardcoded: a non-WooCommerce store sends its
// own, and the envelope must carry them just the same.
$magento = (new Envelope(['magentoVersion' => '2.4.7']))->success(null);
check('any platform field name is carried', $magento['magentoVersion'] === '2.4.7');
check('absent platform fields are simply absent', !isset($magento['woocommerceVersion']));

$fail = $envelope->fromException(\Kolai\Exception\Errors::productNotFound());
check('failure status', $fail['status'] === ErrorCode::STATUS_FAILURE);
check('error code is the Kolai code', $fail['errorCode'] === ErrorCode::PRODUCT_NOT_FOUND);
check('data is null on failure', $fail['data'] === null);

// The Kolai code must never be mistaken for an HTTP status.
$notFound = \Kolai\Exception\Errors::productNotFound();
check('http status is separate from error code', $notFound->getHttpStatus() === 404);
check('product not found is a NotFoundException', $notFound instanceof NotFoundException);
check('unexpected error hides the cause', $envelope->unexpectedError()['errorMessage'] === 'Unexpected error');

// -------------------------------------------------------------- contract ---
$contract = ContractTemplates::render(
    ContractTemplates::DISTANCE_SALES,
    null,
    ['seller_name' => 'Acme Ltd', 'seller_tax_id' => '1234567890']
);
check('seller settings are substituted', strpos($contract['content'], 'Acme Ltd') !== false);
check('substituted placeholder is gone', strpos($contract['content'], '{{seller_name}}') === false);
// Buyer/order placeholders stay for Kolai to fill at checkout.
check('order placeholders are preserved', strpos($contract['content'], '{{buyer_name}}') !== false);
check('preserved placeholders are reported', isset($contract['placeholders']['{{buyer_name}}']));
// An unset setting keeps its placeholder rather than blanking a legal line.
check('unset seller setting keeps its placeholder', strpos($contract['content'], '{{seller_mersis_no}}') !== false);
check(
    'a stored template overrides the default',
    ContractTemplates::render(ContractTemplates::DISTANCE_SALES, '<p>{{buyer_name}}</p>', [])['content'] === '<p>{{buyer_name}}</p>'
);
check(
    'blank stored template falls back to the default',
    strpos(ContractTemplates::render(ContractTemplates::PRELIMINARY_INFO, '   ', [])['content'], 'On Bilgilendirme') !== false
);
check(
    'unknown contract type is rejected',
    throws(static fn () => ContractTemplates::render('nda', null, [])) === BadRequestException::class
);

// A store's own contract is used verbatim — nothing of ours survives in it.
$own = ContractTemplates::render(
    ContractTemplates::DISTANCE_SALES,
    '<h1>Kendi Sozlesmemiz</h1><p>{{seller_name}} / {{buyer_name}} / {{kargo_firmasi}}</p>',
    ['seller_name' => 'Acme Ltd']
);
check('own contract keeps none of the default text', strpos($own['content'], 'MADDE 1') === false);
check('own contract still gets seller settings', strpos($own['content'], 'Acme Ltd') !== false);
check('own contract keeps order placeholders for Kolai', isset($own['placeholders']['{{buyer_name}}']));

// An invented placeholder nothing can fill must be reportable, or it reaches the
// customer as literal braces inside a legal document.
$unknown = ContractTemplates::unknownPlaceholders($own['content']);
check('invented placeholder is reported', $unknown === ['{{kargo_firmasi}}']);
check('known placeholders are not reported as unknown', ContractTemplates::unknownPlaceholders('{{buyer_name}}{{seller_email}}') === []);
check('duplicates are reported once', ContractTemplates::unknownPlaceholders('{{a_b}} {{a_b}}') === ['{{a_b}}']);
check('the bundled defaults have no unfillable placeholders', ContractTemplates::unknownPlaceholders(
    ContractTemplates::defaultTemplate(ContractTemplates::DISTANCE_SALES)
    . ContractTemplates::defaultTemplate(ContractTemplates::PRELIMINARY_INFO)
) === []);

// ------------------------------------------------------------ credentials ---
$sandbox = new IyzicoCredentials(' key ', ' secret ', 'sandbox');
check('credentials are trimmed', $sandbox->apiKey() === 'key' && $sandbox->secretKey() === 'secret');
check('sandbox base url', $sandbox->baseUrl() === IyzicoCredentials::BASE_URL_SANDBOX);
check('production base url', (new IyzicoCredentials('k', 's', 'production'))->baseUrl() === IyzicoCredentials::BASE_URL_PRODUCTION);
// A typo in the environment setting must never point live money at the real API.
check('unknown environment falls back to sandbox', (new IyzicoCredentials('k', 's', 'produciton'))->baseUrl() === IyzicoCredentials::BASE_URL_SANDBOX);
check('empty credentials are not configured', (new IyzicoCredentials('', 's'))->isConfigured() === false);

echo "ok — {$checks} checks passed\n";
