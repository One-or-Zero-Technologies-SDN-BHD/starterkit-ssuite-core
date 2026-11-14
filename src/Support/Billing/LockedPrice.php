<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Billing\CheckoutPricing;
use App\Support\Billing\Currency;
use Illuminate\Validation\ValidationException;

/**
 * Existing subscribers keep the price they signed up at. A later price rise
 * applies to new customers only; a price cut reaches everyone.
 */
class LockedPrice
{
    /** What renewing $plan costs this customer for $cycle. */
    public static function amount(Plan $plan, string $cycle, ?Subscription $own): string
    {
        $list = CheckoutPricing::amount($plan, $cycle);

        // A lock is in the currency the subscriber paid; after the app's billing
        // currency changes, they renew at the list price in the new currency.
        if (! $own || (int) $own->plan_id !== (int) $plan->getKey()
            || Currency::normalize($own->currency) !== Currency::code()) {
            return $list;
        }

        $locked = match ($cycle) {
            'monthly' => $own->locked_price,
            'yearly' => $own->locked_price_yearly,
            default => null,
        };

        if ($locked === null || (float) $locked <= 0 || (float) $locked >= (float) $list) {
            return $list;
        }

        return number_format((float) $locked, 2, '.', '');
    }

    /**
     * Prices to lock on a new subscription. Renewing the same plan carries the
     * earlier lock forward (or today's price, if that is lower).
     *
     * Locks are in $currency (the payment's currency; the app's billing
     * currency by default), which is stored on the subscription.
     *
     * @return array{locked_price: ?string, locked_price_yearly: ?string, currency: string}
     */
    public static function forNewSubscription(Plan $plan, ?Subscription $previous, ?string $currency = null): array
    {
        $currency = Currency::normalize($currency ?? Currency::code());
        $carry = $previous && (int) $previous->plan_id === (int) $plan->getKey()
            && Currency::normalize($previous->currency) === $currency;

        return [
            'locked_price' => self::lock($plan->priceFor('monthly', $currency), $carry ? $previous->locked_price : null),
            'locked_price_yearly' => self::lock($plan->priceFor('yearly', $currency), $carry ? $previous->locked_price_yearly : null),
            'currency' => $currency,
        ];
    }

    /** Move a subscriber to today's prices from their next renewal (admin action). */
    public static function reprice(Subscription $subscription): void
    {
        $plan = $subscription->plan;
        $currency = Currency::normalize($subscription->currency);
        if (! $plan || ((float) $plan->price > 0 && $plan->priceFor('monthly', $currency) <= 0)) {
            throw ValidationException::withMessages([
                'plan' => __('Current pricing is unavailable in this subscription\'s currency. Set a price before repricing.'),
            ]);
        }

        $subscription->update([
            'locked_price' => self::lock($plan->priceFor('monthly', $currency), null),
            'locked_price_yearly' => self::lock($plan->priceFor('yearly', $currency), null),
        ]);
    }

    private static function lock(float $current, mixed $earlier): ?string
    {
        $earlier = $earlier !== null && (float) $earlier > 0 ? (float) $earlier : null;
        $price = $earlier !== null && ($current <= 0 || $earlier < $current) ? $earlier : $current;

        return $price > 0 ? number_format($price, 2, '.', '') : null;
    }
}
