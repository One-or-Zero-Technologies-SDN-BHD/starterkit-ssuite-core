<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\PaymentTransaction;
use App\Support\Billing\Currency;
use Illuminate\Support\Facades\DB;

/** Confirmation-time coupon claim, serialized with the central plan grant. */
class CouponSettlement
{
    public const REVIEW = 'coupon_review';

    public static function grant(PaymentTransaction $payment, array $response = [], bool $retainRecovery = false): bool
    {
        if (! $payment->coupon_id || (new self)->redeem($payment)) {
            return true;
        }

        if (! $retainRecovery) {
            unset($response[SubscriptionActivation::MARKER]);
        }
        $response['_ssuite_coupon_review'] = [
            'reason' => 'coupon_unavailable', 'recorded_at' => now()->toIso8601String(),
            'cleanup_pending' => $retainRecovery,
        ];
        $payment->update(['status' => self::REVIEW, 'paid_at' => $payment->paid_at ?? now(), 'gateway_response' => $response]);

        return false;
    }

    /** Idempotent for this payment, but caps and eligibility gate every new claim. */
    public function redeem(PaymentTransaction $transaction, ?Coupon $coupon = null): ?CouponRedemption
    {
        $coupon ??= $transaction->coupon_id ? Coupon::find($transaction->coupon_id) : null;

        if (! $coupon || $coupon->product !== $transaction->product
            || $transaction->product !== config('app.product_key')) {
            return null;
        }

        // Amounts come off the transaction row — the single source of truth the
        // sessionless gateway callback can also read.
        // Pre-tax throughout: original/discount are pre-tax, so final is too
        // (amount includes SST when it is enabled).
        $discount = (float) ($transaction->discount_amount ?? 0);
        $final = (float) $transaction->preTaxAmount();
        $original = (float) ($transaction->original_amount ?? ($final + $discount));

        $work = function () use ($coupon, $transaction, $original, $discount, $final) {
            /** @var Coupon|null $locked */
            $locked = Coupon::forProduct($transaction->product)->whereKey($coupon->id)->lockForUpdate()->first();

            // Replay guard: same transaction redeemed twice (gateway retry).
            $existing = CouponRedemption::where('payment_transaction_id', $transaction->id)->lockForUpdate()->first();
            if ($existing) {
                return (int) $existing->coupon_id === (int) $locked?->id
                    && (int) $existing->user_id === (int) $transaction->user_id ? $existing : null;
            }

            if (! $locked || $locked->product !== $transaction->product || ! $locked->is_active
                || ! $locked->hasUsesLeft() || ! $locked->isWithinWindow()) {
                return null;
            }

            // Locking current reads also see a redemption committed since checkout.
            // Every coupon is once per user, matching checkout validation.
            if (CouponRedemption::where('coupon_id', $locked->id)
                ->where('user_id', $transaction->user_id)
                ->where('payment_transaction_id', '!=', $transaction->id)
                ->lockForUpdate()->first()) {
                return null;
            }

            $redemption = CouponRedemption::create([
                'coupon_id' => $locked->id,
                'user_id' => $transaction->user_id,
                'tenant_id' => $transaction->tenant_id,
                'plan_id' => $transaction->plan_id,
                'payment_transaction_id' => $transaction->id,
                'product' => $transaction->product ?? config('app.product_key'),
                'code' => $locked->code,
                'billing_cycle' => $transaction->billing_cycle,
                'original_amount' => $original,
                'discount_amount' => $discount,
                'final_amount' => $final,
                'currency' => Currency::normalize($transaction->currency),
            ]);

            $locked->increment('used_count');

            return $redemption;
        };

        $central = DB::connection('central');

        // If a transaction is already open, run inside it — opening a nested one
        // gains nothing (the row lock is already held for the duration of the
        // outer transaction) and the driver rejects the second begin outright.
        // Checked on the PDO as well as Laravel's counter, because an app whose
        // app + central connections resolve to the same database can have a
        // transaction opened through the other connection name (which Laravel's
        // per-connection counter does not see). In production this starts clean,
        // so a real transaction is opened and the lock is held properly.
        $alreadyOpen = $central->transactionLevel() > 0
            || rescue(fn (): bool => $central->getPdo()->inTransaction(), false, false);

        return $alreadyOpen ? $work() : $central->transaction($work);
    }
}
