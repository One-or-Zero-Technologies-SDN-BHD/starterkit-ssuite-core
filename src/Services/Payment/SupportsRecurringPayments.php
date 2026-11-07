<?php

namespace Ssuite\Core\Services\Payment;

use Illuminate\Http\Request;

/**
 * Opt-in capability interface for gateways that can store a payment instrument
 * (card token / FPX e-mandate) and charge it again without the customer
 * revisiting checkout. Kept SEPARATE from PaymentGatewayInterface so the
 * one-off adapters are untouched — a gateway advertises recurring support simply
 * by implementing this interface (PaymentGatewayFactory::supportsRecurring()).
 *
 * Guardrails:
 *  - We never see a PAN. authorize* returns an opaque token + display-safe meta.
 *  - chargeInstrument() MUST honour the idempotency key — a retried webhook or a
 *    double-run scheduler must never double-charge.
 */
interface SupportsRecurringPayments
{
    /**
     * Begin authorising a reusable instrument. Cards: tokenization/3DS. FPX DD:
     * mandate + RM1 verification. Returns ['success', 'redirect_url'?, 'ref'?].
     *
     * @param  array<string,mixed>  $customer  ['name','email','phone','tenant_id','user_id']
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function authorizeInstrument(array $customer, array $options = []): array;

    /**
     * Finish authorisation from the gateway callback → a durable token/mandate ref
     * + display-safe metadata. Returns ['success','token','type','brand','last4','expires_at','mandate_reference'].
     * $ref is the 'ref' authorizeInstrument() returned, kept server-side for this
     * owner: adapters re-query it rather than trust identifiers in the return URL.
     *
     * @return array<string,mixed>
     */
    public function completeAuthorization(Request $request, ?string $ref = null): array;

    /**
     * Charge a stored instrument off-session. MUST be idempotent on $idempotencyKey.
     * Returns ['success' => bool, 'gateway_ref' => ?string, 'error_code' => ?string, 'error_message' => ?string].
     *
     * @return array<string,mixed>
     */
    public function chargeInstrument(string $token, float $amount, string $reference, string $idempotencyKey): array;

    /** Revoke a token / cancel a mandate at the gateway. */
    public function revokeInstrument(string $token): bool;
}
