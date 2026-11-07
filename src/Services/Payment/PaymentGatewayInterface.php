<?php

namespace Ssuite\Core\Services\Payment;

use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    /**
     * Create a payment bill/invoice.
     *
     * @param  string  $refId  Internal order/reference ID
     * @param  array  $customerDetails  ['name', 'email', 'phone']
     * @return array ['success' => bool, 'payment_url' => string, 'transaction_id' => string, 'data' => mixed]
     */
    public function createBill(float $amount, string $description, string $refId, array $customerDetails, array $options = []): array;

    /**
     * Verify a payment transaction status.
     *
     * @param  string  $transactionId  External gateway ID
     * @return array ['success' => bool, 'status' => string, 'data' => mixed]
     */
    public function verifyPayment(string $transactionId): array;

    /**
     * Verify the authenticity of an inbound gateway callback (webhook) by
     * checking its signature. Return false when the signature is missing or
     * does not match; the caller MUST reject (401) without mutating state.
     * Gateways that do not sign callbacks rely on the server-side
     * verifyPayment() re-query and may return true here.
     */
    public function verifyCallbackSignature(Request $request): bool;

    /**
     * Pull our references out of an inbound callback: `transaction_id` (our
     * PaymentTransaction id, as passed to createBill() as $refId) and
     * `gateway_ref` (the gateway's own id for the bill). Either may be null.
     * Return null when the callback is not a payment outcome we act on (e.g. a
     * Stripe event of another type, or a CHIP purchase we did not create via
     * createBill()); the caller acknowledges it without changing anything.
     * These values are only assertions: the caller re-verifies with
     * verifyPayment() against the id stored at checkout.
     *
     * @return array{transaction_id: ?string, gateway_ref: ?string}|null
     */
    public function callbackReferences(Request $request): ?array;
}
