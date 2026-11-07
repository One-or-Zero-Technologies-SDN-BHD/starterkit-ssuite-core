<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\Subscription;
use App\Services\SubscriptionGrace;
use Illuminate\Database\Eloquent\Builder;

/** Read-only lifecycle presentation; does not change entitlements or payment state. */
class SubscriptionStatus
{
    public const FILTERS = ['current', 'expiring', 'grace', 'expired', 'scheduled', 'cancelled', 'all'];

    public static function for(Subscription $subscription): string
    {
        if (! in_array($subscription->status, ['active', 'free', 'trial'], true)) {
            return $subscription->status;
        }
        if ($subscription->starts_at?->isFuture()) {
            return 'scheduled';
        }
        if ($subscription->ends_at && $subscription->ends_at->lte(now())) {
            return $subscription->tenant_id && $subscription->status !== 'free'
                && $subscription->ends_at->gt(now()->subDays(SubscriptionGrace::graceDays()))
                    ? 'grace' : 'expired';
        }

        return $subscription->status;
    }

    public static function apply(Builder $query, string $status): Builder
    {
        if ($status === 'all') {
            return $query;
        }
        if ($status === 'cancelled') {
            return $query->where('status', 'cancelled');
        }

        if ($status === 'expired') {
            return $query->where(function ($states) {
                $states->where('status', 'expired')->orWhere(function ($lapsed) {
                    $lapsed->whereIn('status', ['active', 'free', 'trial'])
                        ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                        ->where('ends_at', '<=', now())
                        ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('status', 'free')
                            ->orWhere('ends_at', '<=', now()->subDays(SubscriptionGrace::graceDays())));
                });
            });
        }

        $query->whereIn('status', ['active', 'free', 'trial']);
        if ($status === 'scheduled') {
            return $query->where('starts_at', '>', now());
        }
        $query->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()));
        if (in_array($status, ['current', 'expiring'], true)) {
            $query->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));

            return $status === 'expiring' ? $query->where('ends_at', '<=', now()->addDays(7)) : $query;
        }
        $query->where('ends_at', '<=', now());
        $cutoff = now()->subDays(SubscriptionGrace::graceDays());
        if ($status === 'grace') {
            return $query->whereNotNull('tenant_id')->where('status', '!=', 'free')->where('ends_at', '>', $cutoff);
        }

        return $query->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('status', 'free')->orWhere('ends_at', '<=', $cutoff));
    }
}
