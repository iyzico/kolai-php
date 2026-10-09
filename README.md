# Kolai PHP Client

Kolai e-ticaret entegrasyonlarının platformdan bağımsız çekirdeği. WooCommerce,
Magento 2, OpenCart ve PrestaShop modülleri bu kütüphaneyi paylaşır; her biri
yalnızca kendi veri katmanını yazar.

```
composer require iyzico/kolai-php
```

PHP >= 7.4, `ext-json`, `ext-mbstring`. Tek giriş noktası `Kolai\` ad alanı, PSR-4.

## Ne içeride, ne değil

Kütüphane **protokolü ve saf iş mantığını** taşır. Mağazanın verisine dokunan
hiçbir şey içinde yok, çünkü o her platformda baştan yazılır.

| Kütüphane | Modül |
|---|---|
| HMAC imza doğrulama, kapsam (scope) kontrolü, tekrar (replay) koruması | İstek nesnesinden `SignedRequest` kurmak, `NonceStore` gerçeklemesi |
| Yanıt zarfı, 1xxx-6xxx hata kodları, istisna hiyerarşisi | Rota/denetleyici kaydı, HTTP yanıtını yazmak |
| Adres normalleştirme (TR plaka kodu, ilçe alan adı geçişi, telefon) | Adresi mağazanın kendi alanlarına yazmak |
| Ürün / yorum DTO eşlemesi | Ürünü, varyasyonu, yorumu veri katmanından okumak |
| Sipariş yükü doğrulaması, indirim ve taksit matematiği | Sipariş oluşturmak, stok düşmek, kargo ücretini hesaplamak |
| iyzico iade/iptal çağrıları, iade tutarı dağıtımı | Sipariş meta verisi, kilit, iade kaydı, yönetim butonu |
| Log kişisel veri maskeleme | Logu bir yere yazmak (PSR-3) |
| Sözleşme şablonları ve yer tutucular | Şablonu ve satıcı ayarlarını saklamak |

Kütüphanede tek bir port arayüzü var: `Kolai\Auth\NonceStore`. Geri kalan her şey
değer alıp değer döndürür — taklit (mock) edilecek bir şey yok.

## Kimlik doğrulama

```php
use Kolai\Auth\{RequestVerifier, SignedRequest, Scope};

$verifier = new RequestVerifier($apiKey, $secretKey, $nonceStore, $psrLogger);

$request = SignedRequest::fromRequestUri(
    $headers['Authorization'] ?? '',
    $_SERVER['REQUEST_URI'],
    $rawBody                      // GET için ''
);

$verifier->verify($request, Scope::CREATE_ORDER);   // başarısızsa UnauthorizedException
```

İmza şöyle kuruluyor:

```
hex HMAC-SHA256( salt . scope . uriPath . rawBody [. timestamp], secretKey )
```

İki tane kolay hata var, ikisi de aynı belirtiyi veriyor (`Invalid signature`):

- **`uriPath` ham istek yolu olmalı**, sorgu dizesi olmadan, imzalayanın gördüğü
  haliyle. Çatının (framework) rotaladığı yol değil, yeniden yazılmış hâli değil.
  Tek karakter farkı imzayı bozar.
- **`rawBody` ham bayt olmalı.** `json_encode(json_decode($body))` anahtar
  sırasını ve boşlukları değiştirir, imza yine bozulur.

Tekrar koruması iki katmanlı: imzalı `timestamp` pencereyi 300 saniyeye indirir,
salt tek kullanımlık olduğu için o pencere içindeki birebir tekrar da reddedilir.
Timestamp göndermeyen eski istemciler yalnızca salt ile korunur (600 saniye) ve
loga kullanımdan kaldırma (deprecation) uyarısı düşülür.

`NonceStore::consume()` **süreçler arası atomik olmalı** — aksi hâlde aynı
tekrarın iki kopyası birlikte geçebilir. WordPress'te `wp_cache_add()` ya da bir
transient, Magento'da önbellek ön yüzü, OpenCart/PrestaShop'ta tekil (unique)
indeksli bir `INSERT`. `InMemoryNonceStore` yalnızca test içindir.

`RequestVerifier::sign()` aynı şemayı üretir; modül test takımları ve kum havuzu
(sandbox) araçları geçerli bir başlık kurmak için bunu kullanır — şemayı yeniden
yazıp kaydırmak yerine.

## Yanıt zarfı

Düz PHP. Kütüphane hiçbir mağaza fonksiyonu çağırmaz:

```php
use Kolai\Protocol\Envelope;

$envelope = new Envelope($platformFields);   // sade dizi, aşağıya bakın

$envelope->success($data, ['pagination' => $pagination]);
$envelope->fromException($kolaiException);
$envelope->unexpectedError();        // 500, nedenini sızdırmaz
```

`$platformFields` zarfa olduğu gibi eklenir. Hem anahtar adları hem değerler
platforma özgü — Kolai arka ucu her mağaza yazılımı için kendi alanını bekler,
bu yüzden kütüphane bunları varsaymak yerine dışarıdan alır. Modül kendi
sürümlerini kendi okur:

| Modül | `$platformFields` |
|---|---|
| WooCommerce | `['woocommerceVersion' => WC()->version, 'wordpressVersion' => get_bloginfo('version')]` |
| Magento 2 | `['magentoVersion' => $productMetadata->getVersion()]` |
| OpenCart | `['opencartVersion' => VERSION]` |
| PrestaShop | `['prestashopVersion' => _PS_VERSION_]` |

`phpVersion`, `systemTime` ve `status` alanlarını zarf kendi doldurur; onları
geçmeniz gerekmez.

## Hatalar

`Kolai\Exception\Errors` tek fabrika. Her neden bir (hata kodu, HTTP durumu)
çiftine bağlı:

```php
throw Errors::productNotFound();          // 404 + 2001
throw Errors::insufficientStock();        // 409 + 4002
throw Errors::discountExceedsTotal();     // 400 + 4003
```

Sınıf HTTP durumunu, kod nedeni taşır — `catch (NotFoundException $e)` çalışır.
`getCode()` kasten boş: Kolai kodunu HTTP durumu sanan bir çağrı `2001` yayınlar.

## İade dağıtımı

iyzico `paymentTransactionId` başına iade yapar, "sipariş" başına değil. Üç
kalemli bir siparişin 150 TL iadesi birkaç çağrı olur ve bölüştürmeyi yanlış
yapmak müşteriye iki kez para dönmesi demek. `RefundAllocator` bu yüzden tamamen
saf: giriş/çıkış yok, saat yok, test edilebilir.

```php
$ledger = $storedRefundLedger;          // ['tx1' => 50.00, ...]
$plan   = RefundAllocator::plan($itemTransactions, $ledger, $amount);

if ($plan['unallocated'] > RefundAllocator::EPSILON) {
    // İstek karşılanamıyor — hiçbir şey göndermeden durun.
}

$operationId = RefundAllocator::newOperationId();
foreach ($plan['steps'] as $step) {
    $result = $client->refund(
        $step['paymentTransactionId'],
        $step['amount'],
        $currency,
        RefundAllocator::conversationId($orderId, $step['paymentTransactionId'], $operationId)
    );
    if (!$result['success']) {
        break;                          // kalan bakiye defterde kalır, tekrar denenebilir
    }
    // Başarılı her adımdan SONRA defteri hemen kalıcı yazın:
    $ledger = RefundAllocator::record($ledger, $step['paymentTransactionId'], $step['amount']);
}
```

Defter (`alreadyRefunded`) tekrar denemeyi güvenli kılan şey. Orada kayıtlı
tutarlar atlanır, yani kısmi bir başarısızlıktan sonra iade yeniden
çalıştırıldığında yalnızca kalan bakiye gider — hareket etmiş kısım bir daha
gitmez. Bu yüzden her başarılı adımdan sonra defteri **hemen** kalıcı yazın;
yazım başarısız olursa para iyzico'da hareket etmiş demektir, durun ve mutabakat
için işaretleyin.

Modülün üstlendiği kısım: sipariş bazlı kilit (eşzamanlı çift iade için), defteri
saklamak, sipariş notu düşmek, yerel iade kaydı.

## Log maskeleme

Kolai sipariş ve kargo gövdeleri isim, e-posta, telefon, TCKN/VKN ve tam adres
taşır. Bunları tutan bir hata ayıklama logu, mağazanın müşteri veritabanının
ikinci ve genelde korumasız bir kopyası olur.

```php
$logger->info('request started', ['body' => Redactor::payload($jsonParams)]);
```

Hassas kapsayıcılar (`buyer`, `billingAddress`, …) bütün olarak düşürülür, hassas
sayıl (scalar) anahtarlar maskelenir, uzun serbest metin kırpılır, kodlanmış yük
2 KB'a sınırlanır.

Gövdeyi ayrıştıramadığınızda `Redactor::unparsedBody($raw)` kullanın: yalnızca
boyutu kaydeder, içeriği asla.

## Sözleşme şablonları

`src/Contract/templates/` altındaki mesafeli satış sözleşmesi ve ön
bilgilendirme formu **başlangıç noktası, zorunluluk değil.** Kendi sözleşmesi
olan mağaza onu `$stored` olarak geçer, paketteki metne hiç bakılmaz:

```php
$contract = ContractTemplates::render(
    ContractTemplates::DISTANCE_SALES,
    $storedTemplate,                       // null -> paketteki varsayılan
    ['seller_name' => 'Acme Ltd', 'seller_tax_id' => '1234567890']
);
// $contract['content']      -> alıcı/sipariş yer tutucuları duruyor
// $contract['placeholders'] -> tüketicinin doldurması gerekenler
```

Sabit olan tek şey iki tür adı (`distance_sales`, `preliminary_info`); metnin
kendisi tamamen mağazanın.

Satıcı yer tutucuları mağaza ayarlarından doldurulur, alıcı ve sipariş yer
tutucuları çıkışta Kolai doldursun diye bırakılır. Ayarı verilmeyen bir yer
tutucu boşaltılmaz, yerinde kalır: hukuki bir metinde boş MERSİS satırı, görünür
şekilde doldurulmamış olandan kötüdür.

Mağaza `{{kargo_firmasi}}` gibi kimsenin dolduramayacağı bir yer tutucu
uydurabilir; o da müşteriye düz süslü parantez olarak gider. Yönetim ekranında
uyarmak için:

```php
ContractTemplates::unknownPlaceholders($content);   // ['{{kargo_firmasi}}']
```

## Test

```
composer test
```

Platform modüllerinin kendi entegrasyon testleri bunun yerini almaz.

## Lisans

MIT.
