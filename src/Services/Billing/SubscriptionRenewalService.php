<?php

namespace Ssuite\Core\Services\Billing;

use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TenantPlan;
use App\Models\User;
use App\Notifications\PaymentFailed;
use App\Notifications\SubscriptionPaymentConfirmed;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\SupportsRecurringPayments;
use App\Services\Payment\UnknownChargeOutcome;
use App\Services\SubscriptionGrace;
use App\Services\SubscriptionService;
use App\Support\Billing\CheckoutPricing;
use App\Support\Billing\Currency;
use App\Support\Billing\LockedPrice;
use App\Support\Billing\OnlinePayments;
use App\Support\Billing\PaymentVerification;
use App\Support\Billing\SubscriptionTerm;
use App\Support\Billing\Tax;
use Illuminate\Support\Facades\Log;

/**
 * Automatic renewal with a saved card / mandate. Runs daily
 * (ssuite:subscription-renew) just BEFORE the reminder ladder
 * (ssuite:subscription-expiry), for opted-in subscriptions due within
 * `billing.renew_lead_days`.
 *
 * Every charge becomes an ordinary PaymentTransaction activated through
 * SubscriptionService::activate(), exactly like a renewal paid by hand, so the
 * term, amount, locked price, affiliate commission and audit trail stay
 * consistent. Plan-change rules apply:
 *
 *  - own plan           → charged at the subscriber's locked price
 *  - scheduled Free     → never charged (ssuite:apply-plan-changes switches it)
 *  - scheduled cheaper  → that plan's list price is charged; activation records it
 *                         as the paid next term and the switch happens at term end
 *  - already paid ahead, cancelled, or a chosen ending → never charged
 *
 * Failure: the attempt is metered, the owner is dunned, the term is left alone,
 * so the reminder ladder and read-only grace take over. Retries run daily until
 * the grace window closes.
 *
 * Double-charge safety: one payment_attempts row per subscription billing period
 * (UNIQUE idempotency_key), claimed atomically before the gateway is called; a
 * success is linked to its PaymentTransaction so an interrupted activation is
 * finished on the next run rather than charged again. A gateway exception is an
 * UNKNOWN outcome (status `unknown`): the same payment is retried with the same
 * gateway key, and only an explicit decline rotates the key.
 */
class SubscriptionRenewalService
{
    /** A run that died mid-charge releases its claim after this long. */
    private const STALE_CLAIM_MINUTES = 60;

    public function __construct(private SubscriptionService $subscriptions) {}

    /**
     * Charge every opted-in subscription due for renewal.
     *
     * @return array<string, int> outcome tally
     */
    public function renewDue(?int $leadDays = null, bool $dryRun = false): array
    {
        $leadDays ??= (int) config('billing.renew_lead_days', 3);

        $due = Subscription::query()
            ->forProduct()
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereNotNull('payment_instrument_id')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now()->addDays($leadDays)->endOfDay())
            ->where('ends_at', '>', now()->subDays(SubscriptionGrace::graceDays()))
            ->with(['paymentInstrument', 'plan', 'scheduledPlan'])
            ->orderBy('id')
            ->get();

        $tally = [];
        foreach ($due as $subscription) {
            $outcome = rescue(fn () => $this->attemptRenewal($subscription, $dryRun), 'error');
            $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
        }

        return $tally;
    }

    /**
     * Retry renewals whose last charge had an unknown outcome (a timeout, a
     * still-processing payment). Runs hourly, so an unknown charge is resolved
     * within hours — well inside the window gateways keep idempotency keys —
     * instead of waiting for the next daily sweep.
     *
     * @return array<string, int> outcome tally
     */
    public function retryUnknown(): array
    {
        $subscriptionIds = PaymentAttempt::query()
            ->where('status', 'unknown')
            ->where('attempted_at', '>', now()->subDays(SubscriptionGrace::graceDays() + (int) config('billing.renew_lead_days', 3) + 1))
            ->pluck('subscription_id')
            ->unique();

        $tally = [];
        Subscription::query()
            ->forProduct()
            ->whereIn('id', $subscriptionIds)
            ->with(['paymentInstrument', 'plan', 'scheduledPlan'])
            ->get()
            ->each(function (Subscription $subscription) use (&$tally) {
                $outcome = rescue(fn () => $this->attemptRenewal($subscription), 'error');
                $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
            });

        return $tally;
    }

    /** Attempt one renewal and return an outcome code. */
    public function attemptRenewal(Subscription $subscription, bool $dryRun = false): string
    {
        $periodEnd = $subscription->ends_at?->copy();
        if (! $periodEnd) {
            return 'skipped_no_term';
        }

        $key = hash('sha256', $subscription->id.'|'.$periodEnd->toDateString());
        $existing = PaymentAttempt::where('idempotency_key', $key)->first();

        // This period was already charged: finish an interrupted activation, or stop.
        if ($existing?->status === 'succeeded') {
            $payment = $existing->payment_transaction_id ? PaymentTransaction::find($existing->payment_transaction_id) : null;
            if ($payment && $payment->status !== 'success' && ! $dryRun) {
                $this->activate($payment, $subscription);

                return 'charged';
            }

            return 'already_renewed';
        }

        // Online payments switched off: no charge, no attempt, no dunning. The
        // reminder ladder still tells the owner to renew (CheckSubscriptionExpiry).
        if (! OnlinePayments::enabled()) {
            return 'payments_disabled';
        }

        if ($subscription->status !== 'active' || ! $subscription->auto_renew) {
            return 'skipped_inactive';
        }

        if ($subscription->scheduled_plan_id && $subscription->scheduled_billing_cycle === 'free') {
            return 'skipped_plan_change';
        }

        if ($subscription->scheduled_plan_id && $subscription->scheduled_transaction_id) {
            return 'skipped_paid_ahead';
        }

        if ($this->endsByChoice($subscription)) {
            return 'skipped_chosen_ending';
        }

        [$plan, $cycle] = $this->chargedPlan($subscription);
        if (! $plan) {
            return 'skipped_no_plan';
        }

        // Renewals are charged in the app's billing currency. A plan (or a
        // scheduled replacement) with no price in it is not renewed rather than
        // charged a mislabeled amount.
        if (! $plan->offeredIn()) {
            return 'skipped_unsupported_currency';
        }

        // Cards are per app: never charge a card saved by another S-Suite product.
        $instrument = $subscription->paymentInstrument;
        if (! $instrument || ! $instrument->isChargeable($subscription->product)) {
            return 'skipped_no_instrument';
        }

        // CHIP charges MYR only.
        if (! Currency::supportedBy($instrument->gateway)) {
            return 'skipped_unsupported_currency';
        }

        try {
            $gateway = PaymentGatewayFactory::make($instrument->gateway);
        } catch (\Throwable) {
            return 'skipped_gateway_error';
        }

        if (! $gateway instanceof SupportsRecurringPayments) {
            return 'skipped_not_recurring';
        }

        $price = $this->amountFor($subscription);
        if ($price <= 0) {
            return 'skipped_no_amount';
        }

        // SST on top of the (pre-tax) renewal price, as at checkout.
        $charge = Tax::apply($price);
        $amount = $charge['total'];

        if ($dryRun) {
            return 'would_charge';
        }

        $attempt = $this->claim($key, $subscription, $instrument->id, $amount);
        if (! $attempt) {
            return 'in_progress';
        }

        // An earlier run whose outcome is unknown (gateway exception) left its
        // payment pending: retry THAT payment, at its amount, with the same key.
        $payment = $attempt->payment_transaction_id
            ? PaymentTransaction::whereKey($attempt->payment_transaction_id)->where('status', 'pending')->first()
            : null;
        if ($payment) {
            $amount = (float) $payment->amount;
        }

        $payment ??= PaymentTransaction::create([
            'user_id' => $subscription->user_id,
            'tenant_id' => $subscription->tenant_id,
            'plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'product' => $subscription->product,
            ...Tax::columns($charge),
            'currency' => Currency::code(),
            'gateway' => $instrument->gateway,
            'status' => 'pending',
            'discount_amount' => 0,
            'original_amount' => $charge['subtotal'],
            'description' => 'Automatic renewal: '.$plan->name.' ('.$cycle.')',
        ]);
        $attempt->update(['payment_transaction_id' => $payment->id, 'amount' => $amount]);

        // The gateway key changes only after an explicit decline (renewal_attempts
        // counts declines): gateways replay the stored result for a reused key,
        // which would turn yesterday's decline into today's too. After an unknown
        // outcome the count is unchanged, so the retry reuses the key and the
        // gateway de-duplicates a charge that did go through.
        $gatewayKey = $key.':'.(int) $subscription->renewal_attempts;
        $reference = 'RENEW-'.$subscription->id.'-'.$periodEnd->format('Ymd');

        try {
            // After an unknown outcome, first find out what happened to that
            // charge (gateways keep idempotency keys only for a while).
            $result = $existing?->status === 'unknown'
                ? $this->resolveUnknown($gateway, $attempt, $reference)
                : null;

            $result ??= $gateway->chargeInstrument((string) $instrument->token, $amount, $reference, $gatewayKey);
        } catch (\Throwable $e) {
            $this->onUnknownOutcome($subscription, $attempt, $payment, $e);

            return 'unknown';
        }

        // A success must be for this renewal and this amount; anything else is
        // held for an admin instead of being granted (or charged again).
        if (($result['success'] ?? false) && isset($result['amount_cents'])
            && ! PaymentVerification::matches($payment, $result, $reference)) {
            $attempt->update(['status' => 'review', 'gateway_ref' => $result['gateway_ref'] ?? null, 'error_code' => 'mismatch', 'error_message' => 'Gateway reported a charge that does not match this renewal.']);
            $payment->update(['gateway_transaction_id' => $result['gateway_ref'] ?? null, 'gateway_response' => ['error' => 'mismatch'] + $result]);

            return 'review';
        }

        if (! ($result['success'] ?? false)) {
            $payment->update(['status' => 'failed', 'gateway_response' => $result]);
            $this->onFailure($subscription, $attempt, $result);

            return 'failed';
        }

        $attempt->update(['status' => 'succeeded', 'gateway_ref' => $result['gateway_ref'] ?? null]);
        $payment->update(['gateway_transaction_id' => $result['gateway_ref'] ?? null]);

        $this->activate($payment, $subscription);

        return 'charged';
    }

    /**
     * Amount to charge: the subscriber's locked price for their own plan (a price
     * rise never reaches them; a cut does), or the list price of a scheduled
     * cheaper plan.
     */
    public function amountFor(Subscription $subscription): float
    {
        [$plan, $cycle] = $this->chargedPlan($subscription);

        if (! $plan) {
            return (float) ($subscription->amount ?? 0);
        }

        if ((int) $plan->id !== (int) $subscription->plan_id) {
            return (float) CheckoutPricing::amount($plan, $cycle);
        }

        $amount = (float) LockedPrice::amount($plan, $cycle, $subscription);

        // A yearly subscriber on a plan without a yearly price: twelve months.
        return $amount <= 0 && $cycle === 'yearly' ? $plan->priceFor('monthly') * 12 : $amount;
    }

    /**
     * The plan and cycle this renewal pays for.
     *
     * @return array{0: ?Plan, 1: string}
     */
    private function chargedPlan(Subscription $subscription): array
    {
        if ($subscription->scheduled_plan_id && $subscription->scheduled_billing_cycle !== 'free') {
            return [$subscription->scheduledPlan, $subscription->scheduled_billing_cycle ?: 'monthly'];
        }

        return [$subscription->plan, $subscription->billing_cycle === 'yearly' ? 'yearly' : 'monthly'];
    }

    /** Reserve this period's attempt row; null when another run holds it. */
    private function claim(string $key, Subscription $subscription, int $instrumentId, float $amount): ?PaymentAttempt
    {
        $attempt = PaymentAttempt::firstOrCreate(
            ['idempotency_key' => $key],
            ['subscription_id' => $subscription->id, 'payment_instrument_id' => $instrumentId, 'amount' => $amount, 'status' => 'pending', 'attempted_at' => now()]
        );

        $claimed = PaymentAttempt::whereKey($attempt->id)
            ->where(function ($q) {
                $q->whereIn('status', ['pending', 'failed', 'unknown'])
                    ->orWhere(fn ($q2) => $q2->where('status', 'processing')->where('attempted_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES)));
            })
            ->update(['status' => 'processing', 'payment_instrument_id' => $instrumentId, 'amount' => $amount, 'attempted_at' => now()]) > 0;

        return $claimed ? $attempt->fresh() : null;
    }

    /** Turn the confirmed charge into the next term and tell the owner. */
    private function activate(PaymentTransaction $payment, Subscription $subscription): void
    {
        $this->subscriptions->activate($payment, ['source' => 'auto_renew']);

        $subscription->refresh();
        if ($subscription->status === 'active') {
            // Paid ahead for a scheduled plan: the row stays until the switch.
            $subscription->forceFill(['renewal_attempts' => 0, 'last_renewal_attempt_at' => now(), 'last_renewal_error' => null])->save();
        }

        $payment->refresh();
        $paidUntil = $payment->subscription_id
            ? Subscription::find($payment->subscription_id)?->ends_at
            : ($subscription->ends_at ? SubscriptionTerm::addTerm($subscription->ends_at->copy(), $payment->billing_cycle ?: 'monthly') : null);

        Log::info('Auto-renewal charged', ['subscription_id' => $subscription->id, 'payment_transaction_id' => $payment->id, 'paid_until' => $paidUntil?->toDateString()]);

        if ($owner = $this->owner($subscription)) {
            rescue(fn () => $owner->notify(new SubscriptionPaymentConfirmed(
                $subscription->tenant_id ? (int) $subscription->tenant_id : null,
                (string) (Plan::find($payment->plan_id)?->name ?? __('your plan')),
                (float) $payment->amount,
                $paidUntil?->format('j M Y'),
                true,
            )), null, false);
        }
    }

    /** Meter the failure and dun the owner; the term is left for the ladder and grace. */
    private function onFailure(Subscription $subscription, PaymentAttempt $attempt, array $result): void
    {
        $attempt->update([
            'status' => 'failed',
            'error_code' => $result['error_code'] ?? null,
            'error_message' => $result['error_message'] ?? 'charge failed',
        ]);

        $subscription->increment('renewal_attempts');
        $subscription->forceFill([
            'last_renewal_attempt_at' => now(),
            'last_renewal_error' => mb_substr((string) ($result['error_message'] ?? 'charge failed'), 0, 250),
        ])->save();

        if ($owner = $this->owner($subscription)) {
            rescue(fn () => $owner->notify(new PaymentFailed($subscription, $attempt, (int) $subscription->renewal_attempts)), null, false);
        }

        Log::warning('Auto-renewal failed', [
            'subscription_id' => $subscription->id,
            'attempt' => $subscription->renewal_attempts,
            'error' => $result['error_message'] ?? null,
        ]);
    }

    /**
     * The gateway call threw (e.g. a timeout): the charge may or may not have
     * gone through. Not a decline: no dunning, no new key. The payment stays
     * pending and the next run retries it with the same idempotency key.
     */
    private function onUnknownOutcome(Subscription $subscription, PaymentAttempt $attempt, PaymentTransaction $payment, \Throwable $e): void
    {
        $attempt->update([
            'status' => 'unknown',
            // The gateway's id for the charge, when it told us one, so the
            // retry can look it up (resolveUnknown) rather than charge again.
            'gateway_ref' => $e instanceof UnknownChargeOutcome && $e->gatewayRef ? $e->gatewayRef : $attempt->gateway_ref,
            'error_code' => 'unknown_outcome',
            'error_message' => mb_substr($e->getMessage(), 0, 250),
        ]);
        $payment->update(['gateway_response' => ['error' => 'unknown_outcome', 'message' => mb_substr($e->getMessage(), 0, 250)]]);
        $subscription->forceFill(['last_renewal_attempt_at' => now()])->save();

        Log::warning('Auto-renewal outcome unknown; will retry with the same key', [
            'subscription_id' => $subscription->id,
            'payment_transaction_id' => $payment->id,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * What became of a charge whose outcome was unknown: its result, null when
     * no charge exists (create one), or an UnknownChargeOutcome while it is
     * still processing. Gateways without a lookup rely on their own keys
     * (CHIP records the purchase per key).
     *
     * @return array<string, mixed>|null
     */
    private function resolveUnknown(SupportsRecurringPayments $gateway, PaymentAttempt $attempt, string $reference): ?array
    {
        if (! method_exists($gateway, 'resolveUnknownCharge')) {
            return null;
        }

        return $gateway->resolveUnknownCharge($attempt->gateway_ref ?: null, $reference);
    }

    private function endsByChoice(Subscription $subscription): bool
    {
        return $subscription->tenant_id
            && (bool) rescue(fn () => TenantPlan::where('tenant_id', $subscription->tenant_id)->value('ends_by_choice'), false, false);
    }

    private function owner(Subscription $subscription): ?User
    {
        return $subscription->user
            ?: ($subscription->tenant?->user_id ? User::find($subscription->tenant->user_id) : null);
    }
}
