<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\Plan;

/** The periods actually offered by a plan, shared by previews and checkout. */
class CheckoutPricing
{
    public static function cycles(Plan $plan): array
    {
        if ($plan->billing_cycle === 'lifetime') {
            return ['lifetime'];
        }

        return $plan->priceFor('yearly') > 0 ? ['monthly', 'yearly'] : ['monthly'];
    }

    public static function defaultCycle(Plan $plan): string
    {
        return in_array($plan->billing_cycle, self::cycles($plan), true)
            ? $plan->billing_cycle : self::cycles($plan)[0];
    }

    /** List price for $cycle in the app's billing currency (see Currency). */
    public static function amount(Plan $plan, string $cycle): string
    {
        return number_format($plan->priceFor($cycle === 'yearly' ? 'yearly' : 'monthly'), 2, '.', '');
    }

    /**
     * Whole-percent saving of the yearly price over twelve monthly payments.
     * Rounded down so a badge never claims more than the customer saves.
     */
    public static function yearlySavingPercent(Plan $plan): int
    {
        $monthly = $plan->priceFor('monthly');
        $yearly = $plan->priceFor('yearly');

        if ($plan->billing_cycle === 'lifetime' || $monthly <= 0 || $yearly <= 0) {
            return 0;
        }

        return max(0, (int) floor(round((1 - $yearly / ($monthly * 12)) * 100, 6)));
    }

    /**
     * The saving shown on the Yearly toggle across the listed plans.
     *
     * @param  iterable<Plan>  $plans
     * @return array{max: int, varies: bool}
     */
    public static function yearlySavingSummary(iterable $plans): array
    {
        $savings = [];
        foreach ($plans as $plan) {
            if (($percent = self::yearlySavingPercent($plan)) > 0) {
                $savings[] = $percent;
            }
        }

        return [
            'max' => $savings === [] ? 0 : max($savings),
            'varies' => count(array_unique($savings)) > 1,
        ];
    }
}
