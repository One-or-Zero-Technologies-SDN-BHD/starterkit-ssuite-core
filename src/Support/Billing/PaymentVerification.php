<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Log;

/**
 * A gateway saying "paid" is not enough to grant anything: the payment it
 * describes must be OURS (reference = our transaction id), for the amount we
 * charged (in cents, SST included) and in our currency. Used by the payment
 * callback, the return URL and automatic renewal.
 *
 * Gateways' verifyPayment() / chargeInstrument() report `reference`,
 * `amount_cents` and `currency` for this check.
 */
class PaymentVerification
{
    /**
     * @param  array<string, mixed>  $verification
     * @param  string|null  $expectedReference  defaults to the transaction id
     */
    public static function matches(PaymentTransaction $transaction, array $verification, ?string $expectedReference = null): bool
    {
        $expected = $expectedReference ?? (string) $transaction->getKey();
        $reference = (string) ($verification['reference'] ?? '');
        $cents = $verification['amount_cents'] ?? null;
        $currency = strtoupper((string) ($verification['currency'] ?? ''));

        $referenceMatches = $reference !== '' && hash_equals($expected, $reference);
        $amountMatches = is_numeric($cents) && (int) $cents === self::cents((float) $transaction->amount);
        $currencyMatches = $currency !== '' && $currency === strtoupper((string) ($transaction->currency ?: 'MYR'));

        if ($referenceMatches && $amountMatches && $currencyMatches) {
            return true;
        }

        Log::warning('Payment verification does not match the transaction; not activated', [
            'transaction_id' => $transaction->getKey(),
            'gateway' => $transaction->gateway,
            'reference_matches' => $referenceMatches,
            'amount_matches' => $amountMatches,
            'currency_matches' => $currencyMatches,
        ]);

        return false;
    }

    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
