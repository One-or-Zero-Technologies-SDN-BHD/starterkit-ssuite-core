<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\AdminPlanOverride;
use App\Services\SubscriptionGrace;
use App\Support\Billing\CheckoutPricing;
use App\Support\Billing\Currency;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use JsonException;
use UnexpectedValueException;

/**
 * Moving a workspace between plans (docs/BILLING-OPERATIONS.md, "Plan changes").
 *
 *  - A more expensive plan starts now. Unused paid time on the current plan is
 *    valued at the rate the customer actually paid and becomes extra days on the
 *    new plan, at the new plan's list price. Nothing is refunded.
 *  - A cheaper plan, or Free, is scheduled for the end of the paid term.
 */
class PlanChange
{
    /** Days one billing period is worth when valuing unused time. */
    public const CYCLE_DAYS = ['monthly' => 365 / 12, 'yearly' => 365];

    /** The live, dated, paid-for subscription a plan change is measured against. */
    public static function current(?Tenant $tenant, int $userId): ?Subscription
    {
        return self::scoped($tenant?->getKey(), $userId)
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->with('plan')
            ->orderByDesc('ends_at')
            ->first();
    }

    /** A cheaper plan (or Free) than the one the customer is paying for now. */
    public static function isDowngrade(Subscription $current, Plan $target): bool
    {
        $currentPlan = $current->plan;

        return $currentPlan !== null
            && (int) $currentPlan->getKey() !== (int) $target->getKey()
            && (float) $currentPlan->price > 0
            && (float) $target->price < (float) $currentPlan->price;
    }

    /**
     * The subscription whose unused time a payment for a different plan turns
     * into extra days. Only a live `active` row counts: rows already replaced by
     * a renewal or another plan are `cancelled`, so their time is never counted
     * twice.
     */
    public static function creditSource(PaymentTransaction $payment): ?Subscription
    {
        return self::scoped($payment->tenant_id, (int) $payment->user_id, $payment->product)
            ->where('status', 'active')
            ->where('plan_id', '!=', $payment->plan_id)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Extra days on $target bought by the unused value of $source.
     *
     * @return array{days: int, value: float}
     */
    public static function credit(?Subscription $source, Plan $target, string $cycle, ?Carbon $at = null): array
    {
        $none = ['days' => 0, 'value' => 0.0];

        if (! $source || ! isset(self::CYCLE_DAYS[$cycle]) || (int) $source->plan_id === (int) $target->getKey()) {
            return $none;
        }

        $value = self::unusedValue($source, $at ?? now());
        $targetPrice = (float) CheckoutPricing::amount($target, $cycle);

        // Unused time was paid in the source's currency; value it in today's
        // billing currency (Bank Negara rate). No rate on file: no credit.
        $value = Currency::convert($value, Currency::normalize($source->currency), Currency::code()) ?? 0.0;

        if ($value <= 0 || $targetPrice <= 0) {
            return $none;
        }

        return [
            'days' => (int) floor(round($value / ($targetPrice / self::CYCLE_DAYS[$cycle]), 6)),
            'value' => round($value, 2),
        ];
    }

    /**
     * What the customer has paid for but not yet used, in currency units.
     * Free, complimentary, fully-couponed and lifetime subscriptions are worth nothing.
     */
    public static function unusedValue(Subscription $subscription, Carbon $at): float
    {
        $cycle = $subscription->billing_cycle;
        $paid = (float) $subscription->amount;

        if (! isset(self::CYCLE_DAYS[$cycle]) || $paid <= 0 || ! $subscription->ends_at
            || $subscription->ends_at->lte($at) || self::isComplimentary($subscription)) {
            return 0.0;
        }

        $remainingDays = max(0, $subscription->ends_at->getTimestamp() - $at->getTimestamp()) / 86400;
        $value = $paid / self::CYCLE_DAYS[$cycle] * $remainingDays;

        // A next term already paid for (an early-paid scheduled change) is entirely unused.
        if ($subscription->scheduled_transaction_id) {
            $value += (float) PaymentTransaction::query()
                ->whereKey($subscription->scheduled_transaction_id)
                ->first()?->preTaxAmount();
        }

        return $value;
    }

    /**
     * The customer's own subscription to $plan, if they still hold it: the
     * latest row for the workspace is on this plan and it has not lapsed beyond
     * the grace window. Lets an existing subscriber renew at their locked price,
     * including after the plan is withdrawn from new customers.
     */
    public static function ownSubscription(?Tenant $tenant, int $userId, Plan $plan): ?Subscription
    {
        $latest = self::latest($tenant?->getKey(), $userId);

        if (! $latest || (int) $latest->plan_id !== (int) $plan->getKey()) {
            return null;
        }

        $stillHeld = $latest->ends_at === null
            || $latest->ends_at->gt(now()->subDays(SubscriptionGrace::graceDays()));

        return $stillHeld ? $latest : null;
    }

    /** The newest subscription row for a workspace (or legacy account) in a product. */
    public static function latest(int|string|null $tenantId, int $userId, ?string $product = null): ?Subscription
    {
        return self::scoped($tenantId, $userId, $product)
            ->whereIn('status', ['active', 'free', 'cancelled'])
            ->orderByDesc('id')
            ->first();
    }

    /** The live subscription waiting for this payment to fund its scheduled change. */
    public static function scheduledFor(PaymentTransaction $payment): ?Subscription
    {
        return self::scoped($payment->tenant_id, (int) $payment->user_id, $payment->product)
            ->where('status', 'active')
            ->where('scheduled_plan_id', $payment->plan_id)
            ->whereNull('scheduled_transaction_id')
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->lockForUpdate()
            ->first();
    }

    private static function isComplimentary(Subscription $subscription): bool
    {
        try {
            return ($subscription->payment_details['source'] ?? null) === AdminPlanOverride::SOURCE_COMP;
        } catch (DecryptException|JsonException|UnexpectedValueException) {
            return true;
        }
    }

    private static function scoped(int|string|null $tenantId, int $userId, ?string $product = null): Builder
    {
        return Subscription::query()
            ->forProduct($product)
            ->when(
                $tenantId,
                fn (Builder $q) => $q->where('tenant_id', $tenantId),
                fn (Builder $q) => $q->whereNull('tenant_id')->where('user_id', $userId)
            );
    }
}
