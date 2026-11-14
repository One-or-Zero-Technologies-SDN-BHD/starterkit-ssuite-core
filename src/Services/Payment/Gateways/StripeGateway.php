<?php

namespace Ssuite\Core\Services\Payment\Gateways;

use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\SupportsRecurringPayments;
use App\Services\Payment\UnknownChargeOutcome;
use App\Support\Billing\Currency;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stripe over its REST API (Laravel HTTP client, like the other adapters; the
 * stripe-php SDK is not a dependency).
 *
 * Automatic renewal: a Checkout Session in setup mode saves a card to a Stripe
 * customer, and each renewal is an off-session PaymentIntent. Stripe honours an
 * Idempotency-Key header natively, so a retried key replays the original result
 * instead of charging again. FPX cannot be saved; renewal is cards only.
 *
 * The stored token is "{customer}:{payment_method}".
 */
class StripeGateway implements PaymentGatewayInterface, SupportsRecurringPayments
{
    protected $secretKey;

    protected $publishableKey;

    protected $webhookSecret;

    protected string $baseUrl = 'https://api.stripe.com/v1';

    public function __construct()
    {
        $this->secretKey = config('services.stripe.secret');
        $this->publishableKey = config('services.stripe.key');
        $this->webhookSecret = config('services.stripe.webhook_secret');
    }

    public function createBill(float $amount, string $description, string $refId, array $customerDetails, array $options = []): array
    {
        // FPX is Malaysian online banking: MYR only. USD payments are by card.
        $currency = Currency::normalize($options['currency'] ?? Currency::code());

        $session = array_filter([
            'payment_method_types' => $options['payment_methods'] ?? ($currency === Currency::MYR ? ['card', 'fpx'] : ['card']),
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($currency),
                    'product_data' => [
                        'name' => $description,
                        'description' => $options['description'] ?? "Payment for {$description}",
                    ],
                    'unit_amount' => $this->cents($amount),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $options['returnUrl'] ?? route('payment.return'),
            // No separate cancel page: the return handler re-verifies the session.
            'cancel_url' => $options['cancelUrl'] ?? $options['returnUrl'] ?? route('payment.return'),
            'client_reference_id' => $refId,
            'customer_email' => $customerDetails['email'] ?? null,
            'metadata' => [
                'reference_id' => $refId,
                'customer_name' => $customerDetails['name'] ?? '',
                'customer_phone' => $customerDetails['phone'] ?? '',
            ],
        ], fn ($value) => $value !== null);

        $response = $this->http()->post("{$this->baseUrl}/checkout/sessions", $session);

        // FPX must be activated in the Stripe dashboard; an account without it
        // refuses the whole session, so retry with the remaining methods.
        if (! $response->successful() && in_array('fpx', $session['payment_method_types'], true)
            && str_contains(strtolower((string) $response->json('error.message')), 'fpx')) {
            $session['payment_method_types'] = array_values(array_diff($session['payment_method_types'], ['fpx'])) ?: ['card'];
            $response = $this->http()->post("{$this->baseUrl}/checkout/sessions", $session);
        }

        $data = $response->json();

        if ($response->successful() && isset($data['id'])) {
            return [
                'success' => true,
                'payment_url' => $data['url'] ?? null,
                // The Checkout Session id, never the PaymentIntent: verifyPayment()
                // retrieves a session, and webhooks name the session. Accounts on
                // an older API version return a payment_intent at creation.
                'transaction_id' => $data['id'],
                'session_id' => $data['id'],
                'data' => $data,
            ];
        }

        Log::error('Stripe createBill error', ['error' => $data['error']['message'] ?? null]);

        return [
            'success' => false,
            'message' => $data['error']['message'] ?? 'Failed to create checkout session',
            'data' => null,
        ];
    }

    public function verifyPayment(string $sessionId): array
    {
        // Payments started before the Checkout Session id was stored carry the
        // PaymentIntent id instead.
        if (str_starts_with($sessionId, 'pi_')) {
            return $this->verifyPaymentIntent($sessionId);
        }

        $response = $this->http()->get("{$this->baseUrl}/checkout/sessions/{$sessionId}", ['expand' => ['payment_intent']]);
        $data = $response->json();

        if (! $response->successful()) {
            Log::error('Stripe verifyPayment error', ['error' => $data['error']['message'] ?? null]);

            return [
                'success' => false,
                'status' => 'failed',
                'message' => $data['error']['message'] ?? 'Failed to retrieve checkout session',
                'data' => null,
            ];
        }

        $intent = is_array($data['payment_intent'] ?? null) ? $data['payment_intent'] : null;
        $paid = ($data['payment_status'] ?? null) === 'paid';
        // An expired session, or a completed one whose (async) payment failed,
        // will never be paid.
        $failed = ! $paid && (($data['status'] ?? null) === 'expired'
            || (($data['status'] ?? null) === 'complete' && in_array($intent['status'] ?? null, ['canceled', 'requires_payment_method'], true)));

        return [
            'success' => $paid,
            'status' => $paid ? 'paid' : ($failed ? 'failed' : 'pending'),
            // For PaymentVerification::matches().
            'reference' => (string) ($data['client_reference_id'] ?? ''),
            'amount_cents' => $data['amount_total'] ?? null,
            'currency' => (string) ($data['currency'] ?? ''),
            'payment_intent' => $intent,
            'data' => $data,
        ];
    }

    /** A legacy (pi_…) payment: verify the PaymentIntent itself. */
    protected function verifyPaymentIntent(string $intentId): array
    {
        $response = $this->http()->get("{$this->baseUrl}/payment_intents/{$intentId}");
        $intent = $response->json();

        if (! $response->successful()) {
            return ['success' => false, 'status' => 'failed', 'data' => null];
        }

        $status = match ($intent['status'] ?? null) {
            'succeeded' => 'paid',
            'canceled' => 'failed',
            default => 'pending',
        };

        return [
            'success' => $status === 'paid',
            'status' => $status,
            'reference' => (string) ($intent['metadata']['reference_id'] ?? ''),
            'amount_cents' => $intent['amount_received'] ?? $intent['amount'] ?? null,
            'currency' => (string) ($intent['currency'] ?? ''),
            'payment_intent' => $intent,
            'data' => $intent,
        ];
    }

    public function verifyCallbackSignature(Request $request): bool
    {
        $header = (string) $request->header('Stripe-Signature', '');
        $secret = (string) $this->webhookSecret;
        if ($header === '' || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1' && $v !== null) {
                $signatures[] = $v;
            }
        }
        if (! $timestamp || empty($signatures)) {
            return false;
        }
        // Stripe's default tolerance: a replayed webhook older than 5 minutes is refused.
        if (! ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, (string) $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stripe webhook events for one-off Checkout payments. createBill() set our
     * transaction id as client_reference_id; setup-mode sessions (saving a card)
     * and every other event type are acknowledged and ignored.
     */
    public function callbackReferences(Request $request): ?array
    {
        $type = (string) $request->input('type', '');
        $session = (array) $request->input('data.object', []);

        if (! in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed'], true)
            || ($session['mode'] ?? null) !== 'payment'
            || ! ctype_digit((string) ($session['client_reference_id'] ?? ''))
            || empty($session['id'])) {
            return null;
        }

        return ['transaction_id' => (string) $session['client_reference_id'], 'gateway_ref' => (string) $session['id']];
    }

    // ------------------------------------------------------ recurring (cards)

    /** A Stripe customer for the workspace + a setup-mode Checkout Session. */
    public function authorizeInstrument(array $customer, array $options = []): array
    {
        $returnUrl = $options['returnUrl'] ?? route('subscription.payment-method.callback');

        // Reuse the workspace's Stripe customer from an earlier saved card.
        $customerId = $options['stripe_customer'] ?? null;
        if (is_string($customerId) && $customerId !== '') {
            return $this->setupSession($customerId, $returnUrl, $customer);
        }

        $created = $this->http()->post("{$this->baseUrl}/customers", array_filter([
            'email' => $customer['email'] ?? null,
            'name' => $customer['name'] ?? null,
            'metadata' => array_filter([
                'product' => config('app.product_key'),
                'tenant_id' => $customer['tenant_id'] ?? null,
                'user_id' => $customer['user_id'] ?? null,
            ], fn ($value) => $value !== null),
        ], fn ($value) => $value !== null));

        if (! $created->successful() || ! $created->json('id')) {
            Log::error('Stripe authorizeInstrument customer error', ['error' => $created->json('error.message')]);

            return ['success' => false];
        }

        return $this->setupSession((string) $created->json('id'), $returnUrl, $customer);
    }

    /** @param  array<string, mixed>  $customer */
    protected function setupSession(string $customerId, string $returnUrl, array $customer): array
    {
        $session = $this->http()->post("{$this->baseUrl}/checkout/sessions", [
            'mode' => 'setup',
            'currency' => strtolower(Currency::code()),
            'customer' => $customerId,
            'payment_method_types' => ['card'],
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'metadata' => ['tenant_id' => (string) ($customer['tenant_id'] ?? '')],
        ]);

        if (! $session->successful() || ! $session->json('url')) {
            Log::error('Stripe authorizeInstrument session error', ['error' => $session->json('error.message')]);

            return ['success' => false];
        }

        return ['success' => true, 'redirect_url' => $session->json('url'), 'ref' => $session->json('id'), 'type' => 'card'];
    }

    /** Re-read the session we created: complete + a succeeded SetupIntent. */
    public function completeAuthorization(Request $request, ?string $ref = null): array
    {
        if (! $ref) {
            return ['success' => false];
        }

        $response = $this->http()->get("{$this->baseUrl}/checkout/sessions/{$ref}", ['expand' => ['setup_intent.payment_method']]);
        $session = $response->json();
        $intent = $session['setup_intent'] ?? null;
        $method = is_array($intent) ? ($intent['payment_method'] ?? null) : null;

        if (! $response->successful()
            || ($session['status'] ?? null) !== 'complete'
            || ($intent['status'] ?? null) !== 'succeeded'
            || ! is_array($method) || empty($method['id']) || empty($session['customer'])) {
            return ['success' => false];
        }

        $card = $method['card'] ?? [];
        $month = (int) ($card['exp_month'] ?? 0);
        $year = (int) ($card['exp_year'] ?? 0);

        return [
            'success' => true,
            'token' => $session['customer'].':'.$method['id'],
            'type' => 'card',
            'brand' => isset($card['brand']) ? ucfirst((string) $card['brand']) : null,
            'last4' => $card['last4'] ?? null,
            'expires_at' => $month >= 1 && $month <= 12 && $year > 0 ? Carbon::create($year, $month, 1)->toDateString() : null,
            'mandate_reference' => null,
        ];
    }

    /**
     * Off-session PaymentIntent, confirmed at once. Declines (incl. a bank asking
     * for 3-D Secure: authentication_required) are failures the owner is dunned
     * for; network errors, 5xx, rate limits and a still-processing intent throw,
     * so the renewal service retries with the SAME key and Stripe replays.
     */
    public function chargeInstrument(string $token, float $amount, string $reference, string $idempotencyKey): array
    {
        [$customer, $method] = array_pad(explode(':', $token, 2), 2, null);
        if (! $customer || ! $method) {
            return $this->declined('invalid_token', 'The saved card is no longer valid. Please save a new card.');
        }

        $response = $this->http()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post("{$this->baseUrl}/payment_intents", [
                'amount' => $this->cents($amount),
                'currency' => strtolower(Currency::code()),
                'customer' => $customer,
                'payment_method' => $method,
                'off_session' => 'true',
                'confirm' => 'true',
                'description' => mb_substr($reference, 0, 1000),
                'metadata' => ['reference_id' => $reference],
            ]);

        return $this->chargeResult($response);
    }

    /**
     * After an unknown outcome: find out what happened to the charge before
     * creating another. Stripe keeps idempotency keys for about a day, so a
     * retry must not rely on the key alone.
     *
     * Returns the charge result, null when no charge exists (safe to create
     * one), or throws UnknownChargeOutcome while it is still processing.
     *
     * @return array<string, mixed>|null
     */
    public function resolveUnknownCharge(?string $gatewayRef, string $reference): ?array
    {
        if ($gatewayRef !== null && str_starts_with($gatewayRef, 'pi_')) {
            $response = $this->http()->get("{$this->baseUrl}/payment_intents/{$gatewayRef}");
            if (! $response->successful()) {
                throw new UnknownChargeOutcome('Stripe PaymentIntent '.$gatewayRef.' could not be read (HTTP '.$response->status().').', $gatewayRef);
            }

            $intent = $response->json();
            $this->assertLookupIntent($intent, $reference, $gatewayRef);

            return $this->intentResult($intent);
        }

        // No id kept: search by our reference (not available on every account).
        $search = $this->http()->get("{$this->baseUrl}/payment_intents/search", [
            'query' => "metadata['reference_id']:'".str_replace("'", '', $reference)."'",
            'limit' => 10,
        ]);
        if (! $search->successful()) {
            throw new UnknownChargeOutcome('Stripe charge search could not be completed (HTTP '.$search->status().').');
        }
        $result = $search->json();
        if (! is_array($result) || ($result['object'] ?? null) !== 'search_result'
            || ! isset($result['data']) || ! is_array($result['data']) || ! array_is_list($result['data'])
            || ! array_key_exists('has_more', $result) || $result['has_more'] !== false) {
            // Missing/partial results are not proof that no earlier charge exists.
            throw new UnknownChargeOutcome('Stripe charge search returned an incomplete or invalid result.');
        }
        foreach ($result['data'] as $intent) {
            $this->assertLookupIntent($intent, $reference);
        }
        $intents = collect($result['data'])->sortByDesc('created');
        $intent = $intents->firstWhere('status', 'succeeded') ?? $intents->first();

        return $intent ? $this->intentResult($intent) : null;
    }

    /** A failed, mismatched or malformed lookup never authorizes another charge. */
    private function assertLookupIntent(mixed $intent, string $reference, ?string $expectedId = null): void
    {
        if (! is_array($intent) || ! is_string($intent['id'] ?? null) || ! str_starts_with($intent['id'], 'pi_')
            || ! is_string($intent['status'] ?? null) || ! is_array($intent['metadata'] ?? null)
            || ($intent['metadata']['reference_id'] ?? null) !== $reference
            || ($expectedId !== null && $intent['id'] !== $expectedId)) {
            throw new UnknownChargeOutcome('Stripe charge lookup did not identify the expected renewal.', $expectedId);
        }
        if ($intent['status'] === 'succeeded'
            && (! is_numeric($intent['amount_received'] ?? $intent['amount'] ?? null)
                || ! is_string($intent['currency'] ?? null) || trim($intent['currency']) === '')) {
            throw new UnknownChargeOutcome('Stripe charge lookup omitted the paid amount or currency.', $intent['id']);
        }
    }

    public function revokeInstrument(string $token): bool
    {
        [, $method] = array_pad(explode(':', $token, 2), 2, null);

        return $method !== null
            && $this->http()->post("{$this->baseUrl}/payment_methods/{$method}/detach")->successful();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Checks the keys without creating a checkout session: key formats, test and
     * live keys not mixed, and the secret key accepted by Stripe's balance API.
     *
     * @return array{success: bool, message: string}
     */
    public function verifyCredentials(): array
    {
        $secret = trim((string) $this->secretKey);
        $publishable = trim((string) $this->publishableKey);

        if ($secret === '') {
            return ['success' => false, 'message' => __('Enter the Stripe Secret Key first.')];
        }
        if (! preg_match('/^(sk|rk)_(test|live)_/', $secret)) {
            return ['success' => false, 'message' => __('The Stripe Secret Key must start with sk_test_ or sk_live_. Re-enter it; a browser may have autofilled a password into this field.')];
        }
        if ($publishable !== '' && ! preg_match('/^pk_(test|live)_/', $publishable)) {
            return ['success' => false, 'message' => __('The Stripe Publishable Key must start with pk_test_ or pk_live_.')];
        }
        if ($publishable !== '' && str_contains($publishable, '_test_') !== str_contains($secret, '_test_')) {
            return ['success' => false, 'message' => __('The Publishable Key and Secret Key are from different modes: one is a test key, the other live.')];
        }

        try {
            $response = $this->http()->get("{$this->baseUrl}/balance");
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => __('Could not reach Stripe: :error', ['error' => $e->getMessage()])];
        }

        if ($response->successful()) {
            $mode = str_contains($secret, '_test_') ? __('test mode') : __('live mode');

            return ['success' => true, 'message' => __('Connected to Stripe (:mode). The Secret Key is valid.', ['mode' => $mode])];
        }

        $reason = (string) ($response->json('error.message') ?: __('HTTP :status', ['status' => $response->status()]));

        return ['success' => false, 'message' => __('Stripe rejected the key: :reason', ['reason' => $reason])];
    }

    protected function http()
    {
        // Http::outbound(): request + connect timeouts on every Stripe call.
        return Http::outbound()->withToken((string) $this->secretKey)->asForm()->acceptJson();
    }

    /** @return array<string, mixed> */
    protected function chargeResult(Response $response): array
    {
        if ($response->successful()) {
            return $this->intentResult((array) $response->json());
        }

        $error = (array) $response->json('error', []);
        // Stripe names the PaymentIntent on errors too; keep it for the retry.
        $intentId = $error['payment_intent']['id'] ?? null;

        // card_error (402) and invalid_request_error (400, e.g. a detached card)
        // are definite: nothing was charged.
        if (in_array($response->status(), [400, 402, 404], true) && in_array($error['type'] ?? null, ['card_error', 'invalid_request_error'], true)) {
            return $this->declined(
                (string) ($error['decline_code'] ?? $error['code'] ?? 'card_declined'),
                (string) ($error['message'] ?? 'Your card was declined.')
            );
        }

        // 409 idempotency_error, 429, 5xx: outcome unknown.
        throw new UnknownChargeOutcome('Stripe charge returned HTTP '.$response->status().': '.($error['message'] ?? 'no message'), is_string($intentId) ? $intentId : null);
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    protected function intentResult(array $intent): array
    {
        return match ($intent['status'] ?? null) {
            'succeeded' => [
                'success' => true, 'gateway_ref' => $intent['id'] ?? null, 'error_code' => null, 'error_message' => null,
                'reference' => (string) ($intent['metadata']['reference_id'] ?? ''),
                'amount_cents' => $intent['amount_received'] ?? $intent['amount'] ?? null,
                'currency' => (string) ($intent['currency'] ?? ''),
            ],
            'requires_action' => $this->declined('authentication_required', 'Your bank needs you to confirm this payment. Please save your card again.'),
            'requires_payment_method', 'canceled' => $this->declined('card_declined', 'Your card was declined.'),
            default => throw new UnknownChargeOutcome('Stripe PaymentIntent '.($intent['id'] ?? '?').' is '.($intent['status'] ?? 'unknown').'.', isset($intent['id']) ? (string) $intent['id'] : null),
        };
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
}
