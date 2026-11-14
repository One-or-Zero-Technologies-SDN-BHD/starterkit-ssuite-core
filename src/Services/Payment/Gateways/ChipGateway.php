<?php

namespace Ssuite\Core\Services\Payment\Gateways;

use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\SupportsRecurringPayments;
use App\Services\Payment\UnknownChargeOutcome;
use App\Support\Billing\Currency;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CHIP Collect (gate.chip-in.asia). Cards can be saved for automatic renewal:
 * a purchase created with force_recurring becomes a recurring token once paid
 * (its id IS the token), and each renewal creates a new purchase charged with
 * that token. CHIP stores the card; scheduling renewals is ours
 * (SubscriptionRenewalService).
 *
 * CHIP has no idempotency header, so chargeInstrument() records the purchase it
 * creates for each idempotency key (central gateway_charge_keys). A retry after
 * an unknown outcome re-reads that purchase instead of charging a new one.
 */
class ChipGateway implements PaymentGatewayInterface, SupportsRecurringPayments
{
    protected $secretKey;

    protected $brandId;

    protected $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.chip.secret_key');
        $this->brandId = config('services.chip.brand_id');
        // CHIP uses one host for test and live; the secret key decides which.
        $this->baseUrl = 'https://gate.chip-in.asia/api/v1';
    }

    public function createBill(float $amount, string $description, string $refId, array $customerDetails, array $options = []): array
    {
        $payload = $this->purchasePayload($amount, $description, $refId, $customerDetails, $options) + [
            'success_redirect' => $options['returnUrl'] ?? route('payment.return'),
            // No separate cancel page: the return handler re-verifies and says
            // "not completed" for an unpaid purchase.
            'failure_redirect' => $options['cancelUrl'] ?? $options['returnUrl'] ?? route('payment.return'),
            'success_callback' => $options['callbackUrl'] ?? route('payment.callback'),
        ];

        $response = $this->http()->post("{$this->baseUrl}/purchases/", $payload);

        $data = $response->json();

        if ($response->successful() && isset($data['id'])) {
            return [
                'success' => true,
                'payment_url' => $data['checkout_url'] ?? null,
                'transaction_id' => $data['id'],
                'data' => $data,
            ];
        }

        Log::error('Chip createBill error', ['response' => $data]);

        return [
            'success' => false,
            'message' => self::errorMessage($data, 'Failed to create purchase'),
            'data' => $data,
        ];
    }

    public function verifyPayment(string $purchaseId): array
    {
        $response = $this->http()->retry(2, 200, throw: false)->get("{$this->baseUrl}/purchases/{$purchaseId}/");

        $data = $response->json();

        if ($response->successful() && isset($data['status'])) {
            $status = self::outcome((string) $data['status']);

            return [
                'success' => $status === 'paid',
                'status' => $status,
                // For PaymentVerification::matches(): whose payment, how much, in what.
                'reference' => (string) ($data['reference'] ?? ''),
                'amount_cents' => $data['purchase']['total'] ?? null,
                'currency' => (string) ($data['purchase']['currency'] ?? ''),
                'data' => $data,
            ];
        }

        return [
            'success' => false,
            'status' => 'failed',
            'data' => $data,
        ];
    }

    /**
     * CHIP purchase status → our outcome. Money has arrived once a purchase is
     * paid, and stays arrived through cleared/settled; refunds and chargebacks
     * are their own outcome (never paid, never "still pending").
     */
    public static function outcome(string $status): string
    {
        return match ($status) {
            'paid', 'cleared', 'settled' => 'paid',
            'refunded', 'pending_refund', 'chargeback' => 'refunded',
            'error', 'cancelled', 'expired', 'blocked', 'overdue', 'released' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Generate payment link
     */
    public function createPaymentLink(float $amount, string $description, string $refId, array $options = []): array
    {
        $payload = [
            'brand_id' => $this->brandId,
            'amount' => $this->cents($amount),
            'currency' => Currency::normalize($options['currency'] ?? Currency::code()),
            'description' => $description,
            'client_reference_id' => $refId,
            'title' => $options['title'] ?? $description,
        ];

        $response = $this->http()->post("{$this->baseUrl}/payment_links/", $payload);

        $data = $response->json();

        if ($response->successful() && isset($data['id'])) {
            return [
                'success' => true,
                'payment_link_url' => $data['url'],
                'link_id' => $data['id'],
                'data' => $data,
            ];
        }

        return [
            'success' => false,
            'message' => $data['message'] ?? 'Failed to create payment link',
            'data' => $data,
        ];
    }

    // ------------------------------------------------------ recurring (cards)

    /**
     * A small card-verification purchase with force_recurring: once the owner
     * pays it on CHIP's checkout, the purchase is a reusable token. The amount is
     * `services.chip.verification_amount` (RM1 by default).
     */
    public function authorizeInstrument(array $customer, array $options = []): array
    {
        $returnUrl = $options['returnUrl'] ?? route('subscription.payment-method.callback');
        $amount = (float) config('services.chip.verification_amount', 1.00);

        $payload = $this->purchasePayload($amount, __('Card verification for automatic renewal'), 'CARD-'.($customer['tenant_id'] ?? '').'-'.now()->format('YmdHis'), $customer) + [
            'force_recurring' => true,
            'payment_method_whitelist' => ['visa', 'mastercard', 'maestro'],
            'success_redirect' => $returnUrl,
            'failure_redirect' => $returnUrl,
            'cancel_redirect' => $returnUrl,
        ];

        $response = $this->http()->post("{$this->baseUrl}/purchases/", $payload);
        $data = $response->json();

        if (! $response->successful() || empty($data['id']) || empty($data['checkout_url'])) {
            Log::error('Chip authorizeInstrument error', ['status' => $response->status(), 'response' => $data]);

            return ['success' => false];
        }

        return ['success' => true, 'redirect_url' => $data['checkout_url'], 'ref' => $data['id'], 'type' => 'card'];
    }

    /**
     * The owner is back from checkout. Paid is not enough: the purchase must
     * report is_recurring_token, or it cannot be charged again.
     */
    public function completeAuthorization(Request $request, ?string $ref = null): array
    {
        if (! $ref) {
            return ['success' => false];
        }

        $response = $this->http()->get("{$this->baseUrl}/purchases/{$ref}/");
        $data = $response->json();

        if (! $response->successful() || ($data['is_recurring_token'] ?? false) !== true) {
            return ['success' => false];
        }

        $card = $data['transaction_data']['extra'] ?? [];
        $month = (int) ($card['expiry_month'] ?? 0);
        $year = (int) ($card['expiry_year'] ?? 0);

        return [
            'success' => true,
            'token' => (string) $data['id'],
            'type' => 'card',
            'brand' => isset($card['card_brand']) ? ucfirst(strtolower((string) $card['card_brand'])) : null,
            'last4' => isset($card['masked_pan']) ? mb_substr(preg_replace('/\D/', '', (string) $card['masked_pan']), -4) : null,
            'expires_at' => $month >= 1 && $month <= 12 && $year > 0
                ? Carbon::create($year < 100 ? 2000 + $year : $year, $month, 1)->toDateString()
                : null,
            'mandate_reference' => null,
        ];
    }

    /**
     * Create (or, on a retry, re-find) the renewal purchase for this key, then
     * charge it with the token. A paid purchase is never charged twice; an
     * answer CHIP has not settled yet (pending_charge) is reported as unknown by
     * throwing, so the next run re-reads the same purchase.
     */
    public function chargeInstrument(string $token, float $amount, string $reference, string $idempotencyKey): array
    {
        $purchaseId = $this->chargeKey($idempotencyKey);

        if ($purchaseId) {
            $existing = $this->http()->get("{$this->baseUrl}/purchases/{$purchaseId}/");
            if (! $existing->successful()) {
                throw new \RuntimeException('CHIP purchase '.$purchaseId.' could not be re-read (HTTP '.$existing->status().').');
            }

            $status = (string) $existing->json('status');
            if (self::outcome($status) === 'paid') {
                return $this->paid($purchaseId, (array) $existing->json());
            }
            if ($status === 'pending_charge') {
                throw new UnknownChargeOutcome('CHIP charge for purchase '.$purchaseId.' is still processing.', $purchaseId);
            }
        } else {
            $tokenPurchase = $this->http()->get("{$this->baseUrl}/purchases/{$token}/");
            if ($tokenPurchase->status() === 404) {
                return $this->declined('invalid_recurring_token', 'The saved card is no longer available.');
            }
            if (! $tokenPurchase->successful()) {
                throw new \RuntimeException('CHIP token purchase could not be read (HTTP '.$tokenPurchase->status().').');
            }

            $client = (array) $tokenPurchase->json('client', []);
            $created = $this->http()->post("{$this->baseUrl}/purchases/", $this->purchasePayload(
                $amount, $reference, $reference, ['email' => $client['email'] ?? null, 'name' => $client['full_name'] ?? null]
            ));

            if (! $created->successful() || ! $created->json('id')) {
                // Nothing was charged: an uncharged purchase simply expires.
                throw new \RuntimeException('CHIP renewal purchase could not be created (HTTP '.$created->status().').');
            }

            // Two runs racing for one key keep the FIRST purchase; the loser's
            // uncharged purchase simply expires.
            $purchaseId = $this->rememberChargeKey($idempotencyKey, (string) $created->json('id'));
        }

        $charge = $this->http()->post("{$this->baseUrl}/purchases/{$purchaseId}/charge/", ['recurring_token' => $token]);

        return $this->chargeResult($charge, $purchaseId);
    }

    public function revokeInstrument(string $token): bool
    {
        return $this->http()->post("{$this->baseUrl}/purchases/{$token}/delete_recurring_token/")->successful();
    }

    /**
     * Chip signs callbacks with RSA-SHA256 over the raw request body, verifiable
     * against the company's public key (configured `chip_public_key`, or the one
     * CHIP publishes at GET /public_key/, cached for a day). With no key at all,
     * every callback is refused: the return URL still settles payments.
     */
    public function verifyCallbackSignature(Request $request): bool
    {
        // Fail closed: an unsigned callback must never be able to settle a
        // payment. Without a configured key we use CHIP's published key.
        $publicKey = (string) config('services.chip.public_key', '');
        if (trim($publicKey) === '') {
            $publicKey = (string) $this->publishedPublicKey();
        }
        if (trim($publicKey) === '') {
            Log::error('Chip callback refused: no CHIP public key is configured or retrievable (set chip_public_key).');

            return false;
        }

        $signature = (string) $request->header('X-Signature', '');
        if ($signature === '') {
            return false;
        }

        $decoded = base64_decode($signature, true);
        if ($decoded === false) {
            return false;
        }

        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            Log::error('Chip callback signature check failed: services.chip.public_key is not a valid PEM public key.');

            return false;
        }

        return openssl_verify($request->getContent(), $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * CHIP posts the Purchase object to success_callback. createBill() put our
     * transaction id in `reference`; anything else (renewal RENEW-… and
     * card-verification CARD-… purchases, which set no callback) is not ours.
     */
    public function callbackReferences(Request $request): ?array
    {
        $reference = (string) $request->input('reference', '');
        $purchaseId = $request->input('id');

        if (! ctype_digit($reference) || ! is_string($purchaseId) || $purchaseId === '') {
            return null;
        }

        return ['transaction_id' => $reference, 'gateway_ref' => $purchaseId];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Every CHIP call goes through Http::outbound() (request + connect timeouts).
     * Writes are deliberately NOT retried: a retried purchase creation or charge
     * can produce two purchases — and two charges. A timed-out charge is an
     * unknown outcome that chargeInstrument() resolves on the next run.
     */
    protected function http()
    {
        return Http::outbound()->withToken((string) $this->secretKey)->acceptJson();
    }

    /** CHIP's callback-signing key for this account, or null when it can't be fetched. */
    protected function publishedPublicKey(): ?string
    {
        if (trim((string) $this->secretKey) === '') {
            return null;
        }

        return rescue(function () {
            $cacheKey = 'chip:public-key:'.hash('sha256', (string) $this->secretKey);
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $response = $this->http()->get("{$this->baseUrl}/public_key/");
            $key = $response->successful() ? $response->json() : null;
            $key = is_string($key) ? $key : (is_string($response->body()) && str_contains($response->body(), 'BEGIN PUBLIC KEY') ? trim($response->body(), "\" \n") : null);
            if (! is_string($key) || ! str_contains($key, 'BEGIN PUBLIC KEY')) {
                return null;
            }

            $key = str_replace('\\n', "\n", $key);
            Cache::put($cacheKey, $key, now()->addDay());

            return $key;
        }, null, false);
    }

    /** @param  array<string, mixed>  $purchase */
    protected function paid(string $purchaseId, array $purchase): array
    {
        return [
            'success' => true, 'gateway_ref' => $purchaseId, 'error_code' => null, 'error_message' => null,
            'reference' => (string) ($purchase['reference'] ?? ''),
            'amount_cents' => $purchase['purchase']['total'] ?? null,
            'currency' => (string) ($purchase['purchase']['currency'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    /**
     * Checks the Secret Key and Brand ID without creating a purchase: lists the
     * brand's payment methods, which CHIP refuses for a wrong key or brand.
     *
     * @return array{success: bool, message: string}
     */
    public function verifyCredentials(): array
    {
        if (trim((string) $this->secretKey) === '' || trim((string) $this->brandId) === '') {
            return ['success' => false, 'message' => __('Enter the CHIP Secret Key and Brand ID first.')];
        }

        try {
            $response = $this->http()->get("{$this->baseUrl}/payment_methods/", [
                'brand_id' => trim((string) $this->brandId),
                'currency' => 'MYR',
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => __('Could not reach CHIP: :error', ['error' => $e->getMessage()])];
        }

        if ($response->successful()) {
            return ['success' => true, 'message' => __('Connected to CHIP. The Secret Key and Brand ID are valid.')];
        }

        $reason = self::errorMessage($response->json(), __('HTTP :status', ['status' => $response->status()]));

        return ['success' => false, 'message' => __('CHIP rejected the keys: :reason', ['reason' => $reason])];
    }

    /**
     * CHIP's error body as one readable line. CHIP answers with
     * {"__all__":[{"message":…}]} or per-field {"client":{"phone":[{"message":…}]}}.
     */
    public static function errorMessage(mixed $data, string $fallback): string
    {
        if (! is_array($data)) {
            return $fallback;
        }
        foreach (['message', 'detail'] as $key) {
            if (is_string($data[$key] ?? null) && trim($data[$key]) !== '') {
                return $data[$key];
            }
        }

        $parts = [];
        $walk = function (mixed $value, string $path) use (&$walk, &$parts): void {
            $label = ($path === '' || $path === '__all__') ? '' : $path.': ';
            if (is_array($value) && is_string($value['message'] ?? null)) {
                $parts[] = $label.$value['message'];

                return;
            }
            if (is_string($value) && trim($value) !== '') {
                $parts[] = $label.$value;

                return;
            }
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $walk($item, is_int($key) ? $path : ltrim($path.'.'.$key, '.'));
                }
            }
        };
        $walk($data, '');
        $parts = array_values(array_unique($parts));

        return $parts === [] ? $fallback : implode(' ', array_slice($parts, 0, 3));
    }

    protected function purchasePayload(float $amount, string $description, string $reference, array $customer, array $options = []): array
    {
        return [
            'brand_id' => $this->brandId,
            'reference' => mb_substr($reference, 0, 128),
            'client' => array_filter([
                'email' => $customer['email'] ?? null,
                'full_name' => $customer['name'] ?? null,
                'phone' => $customer['phone'] ?? null,
            ], fn ($value) => filled($value)),
            'purchase' => [
                'currency' => Currency::normalize($options['currency'] ?? Currency::code()),
                'products' => [[
                    'name' => mb_substr($description, 0, 256),
                    'price' => $this->cents($amount),
                    'quantity' => 1,
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function chargeResult(Response $charge, string $purchaseId): array
    {
        if ($charge->successful()) {
            $status = (string) $charge->json('status');
            if (self::outcome($status) === 'paid') {
                return $this->paid($purchaseId, (array) $charge->json());
            }
            if ($status === 'pending_charge') {
                throw new UnknownChargeOutcome('CHIP charge for purchase '.$purchaseId.' is still processing.', $purchaseId);
            }

            return $this->declined($status ?: 'charge_failed', 'The card could not be charged.');
        }

        if ($charge->status() === 400) {
            $code = (string) ($charge->json('__all__.0.code') ?? '');
            if ($code === 'invalid_recurring_token') {
                return $this->declined($code, 'The saved card is no longer valid. Please save a new card.');
            }

            // purchase_charge_error: the reason is on the newest attempt.
            $attempt = (array) ($this->http()->get("{$this->baseUrl}/purchases/{$purchaseId}/")->json('transaction_data.attempts.0.error') ?? []);

            return $this->declined((string) ($attempt['code'] ?? 'purchase_charge_error'), (string) ($attempt['message'] ?? 'Your card was declined.'));
        }

        // 5xx / unexpected: the charge may or may not have happened.
        throw new UnknownChargeOutcome('CHIP charge returned HTTP '.$charge->status().' for purchase '.$purchaseId.'.', $purchaseId);
    }

    /** @return array<string, mixed> */
    protected function declined(string $code, string $message): array
    {
        return ['success' => false, 'gateway_ref' => null, 'error_code' => $code, 'error_message' => $message];
    }

    protected function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    protected function chargeKey(string $idempotencyKey): ?string
    {
        return DB::connection('central')->table('gateway_charge_keys')
            ->where('gateway', 'chip')->where('idempotency_key', $idempotencyKey)
            ->value('gateway_ref');
    }

    /** Record the purchase for a key unless one is already recorded; returns the recorded one. */
    protected function rememberChargeKey(string $idempotencyKey, string $purchaseId): string
    {
        DB::connection('central')->table('gateway_charge_keys')->insertOrIgnore([
            'gateway' => 'chip', 'idempotency_key' => $idempotencyKey,
            'gateway_ref' => $purchaseId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (string) ($this->chargeKey($idempotencyKey) ?? $purchaseId);
    }
}
