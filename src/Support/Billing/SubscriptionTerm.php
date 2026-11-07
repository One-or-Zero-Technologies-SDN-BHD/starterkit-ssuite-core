<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\PaymentTransaction;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

class SubscriptionTerm
{
    /**
     * Same-plan renewals retain unused time. A different plan takes effect now,
     * plus any extra days converted from the plan it replaces (PlanChange::credit).
     */
    public static function endsAt(PaymentTransaction $payment, string $cycle, int $extraDays = 0): ?Carbon
    {
        if (in_array($cycle, ['lifetime', 'free'], true)) {
            return null;
        }

        $current = Subscription::forProduct($payment->product)
            ->where('plan_id', $payment->plan_id)
            ->whereIn('status', ['active', 'free'])
            ->when($payment->tenant_id,
                fn ($q) => $q->where('tenant_id', $payment->tenant_id),
                fn ($q) => $q->whereNull('tenant_id')->where('user_id', $payment->user_id))
            ->orderByDesc('ends_at')->lockForUpdate()->first();

        // Never shorten a permanent grant by renewing that same plan.
        if ($current && $current->ends_at === null) {
            return null;
        }

        $renewing = (bool) $current?->ends_at?->isFuture();
        $endsAt = self::addTerm($renewing ? $current->ends_at->copy() : now(), $cycle);

        return ! $renewing && $extraDays > 0 ? $endsAt?->addDays($extraDays) : $endsAt;
    }

    /** One billing period after $from (null for a lifetime or free term). */
    public static function addTerm(Carbon $from, string $cycle): ?Carbon
    {
        return match ($cycle) {
            'lifetime', 'free' => null,
            'yearly' => $from->addYearNoOverflow(),
            default => $from->addMonthNoOverflow(),
        };
    }
}
