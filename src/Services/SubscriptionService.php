<?php

namespace Ssuite\Core\Services;

use App\Models\ActivityLog;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\TenantPlan;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\AffiliateService;
use App\Services\CouponService;
use App\Services\FeatureFlags;
use App\Services\SubscriptionGrace;
use App\Support\Billing\AddonSettlement;
use App\Support\Billing\Currency;
use App\Support\Billing\LockedPrice;
use App\Support\Billing\PlanChange;
use App\Support\Billing\SubscriptionActivation;
use App\Support\Billing\SubscriptionTerm;
use App\Support\Billing\SubscriptionTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a successful payment into an active subscription.
 *
 * Extracted verbatim from SubscriptionController::activateSubscription so the
 * activation logic lives in a single, testable place off the controller (thinner
 * controllers drift less — see docs/TEMPLATE-PARITY.md). Behaviour is unchanged:
 * the controller now delegates here.
 */
class SubscriptionService
{
    /**
     * Activate the plan a payment transaction paid for. Idempotent — a
     * transaction already marked success can only reconcile its pending local projection.
     *
     * @param  array<string,mixed>  $gatewayResponse
     * @param  array<string,mixed>  $pending  session('pending_payment') fallback for cycle/plan
     */
    public function activate(PaymentTransaction $transaction, array $gatewayResponse = [], array $pending = []): void
    {
        if ($transaction->status === 'success') {
            SubscriptionActivation::for()->reconcile($transaction);

            return; // No new charge or central subscription on replay
        }

        // Provider data cannot create the server-owned recovery marker.
        unset($gatewayResponse[SubscriptionActivation::MARKER]);

        // payment_transactions is a shared central table: settle only this
        // app's payments (plans and add-ons).
        $product = (string) $transaction->product;
        if ($product !== config('app.product_key')) {
            Log::warning('Activation refused: the payment belongs to another product', [
                'transaction_id' => $transaction->id,
                'product' => $product,
            ]);

            return;
        }

        if ($transaction->addon_type) {
            $this->activateAddon($transaction, $gatewayResponse);

            return;
        }

        SubscriptionActivation::for()->run($transaction, function () use ($transaction, $gatewayResponse, $pending): void {
            $billingCycle = $transaction->billing_cycle ?: ($pending['billing_cycle'] ?? 'monthly');
            $planId = $transaction->plan_id ?: ($pending['plan_id'] ?? null);
            $tenantId = $transaction->tenant_id;

            // Scope to THIS product: `plans` is on the shared central database, so an
            // unscoped find() could resolve a sibling app's plan and grant it here.
            $plan = $planId ? Plan::forProduct()->find($planId) : null;
            if (! $plan) {
                Log::warning('Activation attempted but plan not found', ['transaction_id' => $transaction->id]);

                return;
            }

            $subscription = null;

            $claimed = false;

            $deferredFor = null;

            $credit = ['days' => 0, 'value' => 0.0];

            DB::connection('central')->transaction(function () use ($transaction, $plan, $billingCycle, $tenantId, $gatewayResponse, &$subscription, &$claimed, &$deferredFor, &$credit) {
                // Claim the payment before granting anything. The gateway webhook and the
                // user's return URL often arrive together and both pass the status check
                // above; this conditional UPDATE lets exactly one of them through. It runs
                // inside a central-connection transaction (payment_transactions and
                // subscriptions live on central, not on the app database), so if anything
                // below throws, the claim rolls back with the rest and a gateway retry can
                // still finish the job.
                $claimed = PaymentTransaction::whereKey($transaction->getKey())
                    ->where('status', '!=', 'success')
                    ->update(['status' => 'success', 'paid_at' => now()]) > 0;

                if (! $claimed) {
                    return;
                }

                // Paying early for a scheduled cheaper plan: the current plan runs to
                // the end of its term and the switch happens then
                // (ApplyScheduledPlanChanges). The workspace plan row is extended to
                // the end of the new term so the hand-over never drops to Free.
                if ($deferredFor = PlanChange::scheduledFor($transaction)) {
                    $deferredFor->update([
                        'scheduled_transaction_id' => $transaction->id,
                        'scheduled_billing_cycle' => $billingCycle,
                    ]);

                    $transaction->update(['gateway_response' => $gatewayResponse]);

                    if ($tenantId) {
                        TenantPlan::where('tenant_id', $tenantId)->update([
                            'ends_at' => SubscriptionTerm::addTerm($deferredFor->ends_at->copy(), $billingCycle),
                            'ends_by_choice' => false,
                        ]);
                    }

                    return;
                }

                DB::transaction(function () use ($transaction, $plan, $billingCycle, $tenantId, $gatewayResponse, &$subscription, &$credit) {
                    $previous = PlanChange::latest($tenantId, (int) $transaction->user_id, $transaction->product);
                    $credit = PlanChange::credit(PlanChange::creditSource($transaction), $plan, $billingCycle);
                    $endsAt = SubscriptionTerm::endsAt($transaction, $billingCycle, $credit['days']);

                    // Cancel existing active/free subscriptions — scoped to the workspace
                    // being paid for (tenant billing) or, for legacy user-tied
                    // transactions, to the purchaser.
                    Subscription::forProduct()
                        ->whereIn('status', ['active', 'free'])
                        ->when(
                            $tenantId,
                            fn ($q) => $q->where('tenant_id', $tenantId),
                            fn ($q) => $q->where('user_id', $transaction->user_id)->whereNull('tenant_id')
                        )
                        ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

                    $subscription = Subscription::create([
                        'user_id' => $transaction->user_id,
                        'tenant_id' => $tenantId,
                        'plan_id' => $plan->id,
                        'product' => config('app.product_key'),
                        'status' => 'active',
                        // Pre-tax: credit, locks and commission never include SST.
                        'amount' => $transaction->preTaxAmount(),
                        'billing_cycle' => $billingCycle,
                        'starts_at' => now(),
                        'ends_at' => $endsAt,
                        'payment_gateway' => $transaction->gateway,
                        'gateway_subscription_id' => $transaction->gateway_transaction_id,
                        'payment_details' => array_filter([
                            'billing_period' => $billingCycle,
                            'upgrade_credit_days' => $credit['days'] ?: null,
                            'upgrade_credit_value' => $credit['days'] ? $credit['value'] : null,
                        ], fn ($value) => $value !== null),
                        ...LockedPrice::forNewSubscription($plan, $previous, $transaction->currency),
                        ...self::renewalSettings($previous),
                    ]);

                    $transaction->update([
                        'status' => 'success',
                        'subscription_id' => $subscription->id,
                        'gateway_response' => $gatewayResponse,
                        'paid_at' => now(),
                    ]);

                    if ($tenantId) {
                        // Tenant billing: the workspace's own plan row.
                        TenantPlan::updateOrCreate(
                            ['tenant_id' => $tenantId],
                            [
                                'plan_id' => $plan->id,
                                'status' => 'active',
                                'source' => TenantPlan::SOURCE_PURCHASE,
                                'starts_at' => now(),
                                'ends_at' => $endsAt,
                                'ends_by_choice' => false,
                                'created_by' => $transaction->user_id,
                            ]
                        );

                        // Dual-write (transition safety): keep the owner's legacy
                        // user_plans in sync while billing.user_fallback is on — but only
                        // when the purchaser IS the owner, so a delegated billing member
                        // never mutates someone else's user plan.
                        if (config('billing.user_fallback')) {
                            $ownerId = Tenant::find($tenantId)?->user_id;
                            if ($ownerId && (int) $ownerId === (int) $transaction->user_id) {
                                UserPlan::updateOrCreate(
                                    ['user_id' => $transaction->user_id],
                                    [
                                        'plan_id' => $plan->id,
                                        'status' => 'active',
                                        'starts_at' => now(),
                                        'ends_at' => $endsAt,
                                    ]
                                );
                            }
                        }
                    } else {
                        // Legacy user-tied activation: sync local UserPlan so plan limits
                        // resolve correctly.
                        UserPlan::updateOrCreate(
                            ['user_id' => $transaction->user_id],
                            [
                                'plan_id' => $plan->id,
                                'status' => 'active',
                                'starts_at' => now(),
                                'ends_at' => $endsAt,
                            ]
                        );
                    }
                });
            });

            SubscriptionActivation::for()->afterCommit(function () use ($transaction, $plan, $billingCycle, $tenantId, &$claimed, &$subscription, &$credit, &$deferredFor): void {

                if (! $claimed) {
                    return; // Another request already activated this payment.
                }

                if ($tenantId) {
                    FeatureFlags::flushTenant($tenantId);
                    SubscriptionGrace::bust($tenantId);
                }

                if ($deferredFor) {
                    $this->recordDeferredPayment($transaction, $deferredFor, $plan, $billingCycle);

                    return;
                }

                // Audit trail for the admin /subscriptions History tab. Logged AFTER the
                // transaction commits — activity_logs lives on the central connection, so
                // it would not roll back with an app-DB failure and a rolled-back
                // activation must not leave a "plan activated" entry behind. The actor is
                // passed explicitly: a gateway callback has no session, so Auth::user() is
                // null and the history would otherwise be unable to name the purchaser.
                rescue(function () use ($subscription, $plan, $tenantId, $transaction, $billingCycle, $credit) {
                    $tenant = $tenantId ? Tenant::find($tenantId) : null;

                    ActivityLog::record(
                        'subscription_activated',
                        $tenant,
                        [
                            'plan' => $plan->name,
                            'plan_id' => $plan->id,
                            'amount' => (float) $transaction->amount,
                            'tax' => $transaction->taxCharged() ?: null,
                            'billing_cycle' => $billingCycle,
                            'gateway' => $transaction->gateway,
                            'source' => 'self_serve',
                            'ends_at' => optional($subscription?->ends_at)->toDateString(),
                            'upgrade_credit_days' => $credit['days'] ?: null,
                        ],
                        $transaction->user_id ? User::find($transaction->user_id) : null
                    );
                }, null, false);

                // Affiliate attribution: if the purchaser was referred, record the pending
                // commission for this paid subscription. Best-effort + idempotent (the
                // service no-ops when affiliate is disabled, the buyer wasn't referred, or a
                // commission already exists), so it never blocks or double-books activation.
                if ($subscription) {
                    // A checkout with an affiliate's coupon credits that affiliate (when
                    // the buyer wasn't referred already), so book that first.
                    rescue(fn () => AffiliateService::attributeCoupon($transaction), null, false);
                    rescue(fn () => AffiliateService::processSubscriptionCommission($subscription), null, false);
                }

                // Coupon redemption is recorded HERE — on confirmed payment — not at
                // checkout, so an abandoned cart never burns a use. Reads the coupon off
                // the transaction row (the gateway callback has no session), and is
                // idempotent per transaction, so a replayed callback won't double-count.
                if ($transaction->coupon_id) {
                    rescue(fn () => app(CouponService::class)->redeem($transaction), null, false);
                }
            });
        });
    }

    /**
     * Activate the FREE plan for a workspace (tenant billing) or, when there is no
     * tenant, for the user directly (legacy). The free counterpart of activate():
     * both activation paths now funnel through this service. Cancels the current
     * active/free subscription, writes a never-expiring free Subscription, and
     * syncs the app-DB plan rows. The controller keeps the guards + redirects.
     */
    public function activateFree(Plan $plan, ?Tenant $tenant, int $userId): void
    {
        app(SubscriptionTransition::class)->run($tenant, $userId, null, function (?Subscription $current) use ($plan, $tenant, $userId): array {
            $free = $current?->status === 'free' && (int) $current->plan_id === (int) $plan->id && (int) $current->user_id === $userId
                ? $current
                : $this->replaceWithFree($plan, $tenant, $userId);

            return ['free', $free, null];
        });
    }

    private function replaceWithFree(Plan $plan, ?Tenant $tenant, int $userId): Subscription
    {
        Subscription::forProduct()
            ->whereIn('status', ['active', 'free'])
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id), fn ($q) => $q->where('user_id', $userId)->whereNull('tenant_id'))
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return Subscription::create([
            'user_id' => $userId,
            'tenant_id' => $tenant?->id,
            'plan_id' => $plan->id,
            'product' => config('app.product_key'),
            'status' => 'free',
            'amount' => 0,
            'billing_cycle' => 'free',
            'currency' => Currency::code(),
            'starts_at' => now(),
            'ends_at' => null,
        ]);
    }

    /**
     * Schedule a cheaper plan (or Free) to start when $current's paid term ends.
     * Returns false when the next term is already paid for and cannot be changed here.
     */
    public function scheduleChange(Subscription $current, Plan $target, string $billingCycle, int $userId): bool
    {
        $toFree = (float) $target->price <= 0;

        if (! $this->updateUnpaidSchedule($current, [
            'scheduled_plan_id' => $target->id,
            'scheduled_billing_cycle' => $toFree ? 'free' : $billingCycle,
            'scheduled_at' => now(),
        ])) {
            return false;
        }

        $this->markChosenEnding($current, $toFree);

        $this->log('subscription_change_scheduled', $current, [
            'plan' => $current->plan?->name,
            'scheduled_plan' => $target->name,
            'scheduled_plan_id' => $target->id,
            'billing_cycle' => $toFree ? 'free' : $billingCycle,
            'starts_on' => $current->ends_at?->toDateString(),
        ], $userId);

        return true;
    }

    /** Keep the current plan. False when the next plan is already paid for. */
    public function cancelScheduledChange(Subscription $current, int $userId): bool
    {
        $scheduled = $current->scheduledPlan?->name;

        if (! $this->updateUnpaidSchedule($current, ['scheduled_plan_id' => null, 'scheduled_billing_cycle' => null, 'scheduled_at' => null])) {
            return false;
        }

        $this->markChosenEnding($current, false);

        $this->log('subscription_change_cancelled', $current, [
            'plan' => $current->plan?->name,
            'scheduled_plan' => $scheduled,
        ], $userId);

        return true;
    }

    /** A payment callback may have funded the schedule since the caller loaded it. */
    private function updateUnpaidSchedule(Subscription $current, array $attributes): bool
    {
        return DB::connection('central')->transaction(function () use ($current, $attributes): bool {
            $locked = Subscription::query()->forProduct()->lockForUpdate()->find($current->id);
            if (! $locked || $locked->status !== 'active' || $locked->scheduled_transaction_id) {
                return false;
            }

            return $locked->update($attributes);
        });
    }

    /**
     * Carry out a scheduled change once the paid term has ended. Idempotent: the
     * row is locked and re-checked, so overlapping runs apply it once.
     *
     * @return string one of: free, switched, lapsed, skipped
     */
    public function applyScheduledChange(Subscription $subscription): string
    {
        if ($subscription->product !== config('app.product_key')) {
            return 'skipped';
        }

        $clear = ['scheduled_plan_id' => null, 'scheduled_billing_cycle' => null, 'scheduled_at' => null];
        $tenant = $subscription->tenant_id ? Tenant::find($subscription->tenant_id) : null;
        if ($subscription->tenant_id && ! $tenant) {
            $this->updateUnpaidSchedule($subscription, $clear);

            return 'skipped';
        }

        $paymentId = $subscription->scheduled_transaction_id ? (int) $subscription->scheduled_transaction_id : null;

        return app(SubscriptionTransition::class)->run($tenant, (int) $subscription->user_id, $paymentId, function (?Subscription $current, ?PaymentTransaction $payment) use ($subscription, $tenant, $paymentId, $clear): array {
            if (! $subscription->is($current) || $current->status !== 'active' || ! $current->scheduled_plan_id || ! $current->ends_at || $current->ends_at->isFuture()
                || ($current->scheduled_transaction_id ? (int) $current->scheduled_transaction_id : null) !== $paymentId) {
                return ['skipped', null, null];
            }

            $target = Plan::forProduct()->find($current->scheduled_plan_id);
            if (! $target) {
                if (! $paymentId) {
                    $current->update($clear);
                }

                return ['skipped', null, null];
            }

            if (! $paymentId && (float) $target->price <= 0) {
                $free = $this->replaceWithFree($target, $tenant, (int) $current->user_id);
                $current->update($clear);

                return ['free', $free, $current];
            }

            if (! $paymentId) {
                $current->update($clear);
                DB::connection('central')->afterCommit(fn () => $this->log('subscription_change_lapsed', $current, [
                    'plan' => $current->plan?->name, 'scheduled_plan' => $target->name,
                ]));

                return ['lapsed', null, null];
            }

            if (! $payment || $payment->status !== 'success' || $payment->subscription_id
                || (int) $payment->plan_id !== (int) $target->id
                || (int) $payment->tenant_id !== (int) $current->tenant_id
                || (! $tenant && (int) $payment->user_id !== (int) $current->user_id)) {
                return ['skipped', null, null];
            }

            return ['switched', $this->switchToPaidPlan($current, $target, $payment, $clear), $current];
        });
    }

    /** Start the early-paid scheduled plan where the old term ends. */
    private function switchToPaidPlan(Subscription $old, Plan $target, PaymentTransaction $payment, array $clear): Subscription
    {
        $cycle = $old->scheduled_billing_cycle ?: ($payment->billing_cycle ?: 'monthly');
        $startsAt = $old->ends_at->copy();
        $endsAt = SubscriptionTerm::addTerm($startsAt->copy(), $cycle);

        $old->update($clear + ['status' => 'cancelled', 'cancelled_at' => now()]);

        $new = Subscription::create([
            'user_id' => $old->user_id,
            'tenant_id' => $old->tenant_id,
            'plan_id' => $target->id,
            'product' => $old->product,
            'status' => 'active',
            'amount' => $payment->preTaxAmount(),
            'billing_cycle' => $cycle,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'payment_gateway' => $payment->gateway,
            'gateway_subscription_id' => $payment->gateway_transaction_id,
            'payment_details' => ['billing_period' => $cycle, 'source' => 'scheduled_change'],
            ...LockedPrice::forNewSubscription($target, null, $payment->currency),
            ...self::renewalSettings($old),
        ]);

        $payment->update(['subscription_id' => $new->id]);

        return $new;
    }

    /** The early payment for a scheduled plan: audited, coupon and affiliate coupon booked now. */
    private function recordDeferredPayment(PaymentTransaction $transaction, Subscription $current, Plan $plan, string $billingCycle): void
    {
        $this->log('subscription_change_paid', $current, [
            'plan' => $current->plan?->name,
            'scheduled_plan' => $plan->name,
            'amount' => (float) $transaction->amount,
            'billing_cycle' => $billingCycle,
            'starts_on' => $current->ends_at?->toDateString(),
        ], $transaction->user_id ? (int) $transaction->user_id : null);

        rescue(fn () => AffiliateService::attributeCoupon($transaction), null, false);

        if ($transaction->coupon_id) {
            rescue(fn () => app(CouponService::class)->redeem($transaction), null, false);
        }
    }

    /**
     * A plan ending the customer chose (switching to Free, or cancelling) skips
     * the read-only grace window; an unpaid lapse keeps it.
     */
    public function markChosenEnding(Subscription $subscription, bool $chosen): void
    {
        if (! $subscription->tenant_id) {
            return;
        }

        rescue(fn () => TenantPlan::where('tenant_id', $subscription->tenant_id)->update(['ends_by_choice' => $chosen]), null, false);
        SubscriptionGrace::bust($subscription->tenant_id);
    }

    /**
     * A paid workspace add-on: one more month from the later of now and the
     * current end. Items that need setup wait for an admin (status requested);
     * an add-on that is already live just extends. No coupons or commission.
     */
    private function activateAddon(PaymentTransaction $transaction, array $gatewayResponse): void
    {
        $type = (string) $transaction->addon_type;
        $item = TenantAddon::catalog()[$type] ?? null;

        if (! $item || ! $transaction->tenant_id) {
            Log::warning('Add-on activation attempted for an unknown add-on or without a workspace', ['transaction_id' => $transaction->id]);

            return;
        }

        $addon = AddonSettlement::apply($transaction, $gatewayResponse, 'addon', function () use ($transaction, $type, $item): TenantAddon {
            $addon = TenantAddon::query()
                ->forTenant((int) $transaction->tenant_id)
                ->where('addon_type', $type)
                ->whereIn('status', [TenantAddon::STATUS_ACTIVE, TenantAddon::STATUS_REQUESTED])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            // Recover the previous implementation's interrupted latest grant.
            // New purchases retain a receipt for every payment, not only this ID.
            if ($addon && (int) $addon->last_transaction_id === (int) $transaction->id) {
                return $addon;
            }

            // A permanent add-on (active, no end date) stays permanent.
            $permanent = $addon?->isPermanent() ?? false;
            $from = $addon?->ends_at?->isFuture() ? $addon->ends_at->copy() : now();
            $waitsForSetup = $item['requires_setup'] && $addon?->status !== TenantAddon::STATUS_ACTIVE;

            $attributes = [
                'monthly_price' => $item['monthly_price'],
                'setup_fee' => $item['setup_fee'],
                'currency' => Currency::normalize($transaction->currency),
                'ends_at' => $permanent ? null : $from->addMonthNoOverflow(),
                'last_transaction_id' => $transaction->id,
            ];

            if ($waitsForSetup) {
                $attributes += [
                    'status' => TenantAddon::STATUS_REQUESTED,
                    'requested_by' => $addon?->requested_by ?? $transaction->user_id,
                    'requested_at' => $addon?->requested_at ?? now(),
                    'request_note' => $addon?->request_note ?? 'Paid online — awaiting setup',
                ];
            } elseif ($addon?->status !== TenantAddon::STATUS_ACTIVE) {
                $attributes += [
                    'status' => TenantAddon::STATUS_ACTIVE,
                    'activated_by' => $transaction->user_id,
                    'activated_at' => now(),
                ];
            }

            if ($addon) {
                $addon->update($attributes);

                return $addon;
            }

            return TenantAddon::create($attributes + [
                'tenant_id' => $transaction->tenant_id,
                'addon_type' => $type,
            ]);
        });

        if (! $addon) {
            return;
        }

        FeatureFlags::flushTenant((int) $transaction->tenant_id);

        rescue(fn () => ActivityLog::record(
            'addon_purchased',
            Tenant::find($transaction->tenant_id),
            [
                'addon' => $item['name'],
                'addon_type' => $type,
                'status' => $addon->status,
                'amount' => (float) $transaction->amount,
                'tax' => $transaction->taxCharged() ?: null,
                'ends_at' => optional($addon->ends_at)->toDateString(),
            ],
            $transaction->user_id ? User::find($transaction->user_id) : null
        ), null, false);
    }

    /**
     * Automatic renewal belongs to the workspace, not to one subscription row:
     * a renewal, upgrade or scheduled switch keeps the owner's choice and saved
     * card. A cancelled subscription has already had auto_renew turned off.
     *
     * @return array{auto_renew: bool, payment_instrument_id: ?int, renewal_attempts: int}
     */
    public static function renewalSettings(?Subscription $previous): array
    {
        return [
            'auto_renew' => (bool) ($previous?->auto_renew ?? false),
            'payment_instrument_id' => $previous?->payment_instrument_id,
            'renewal_attempts' => 0,
        ];
    }

    /** @param  array<string, mixed>  $details */
    private function log(string $action, Subscription $subscription, array $details, ?int $actorId = null): void
    {
        rescue(fn () => ActivityLog::record(
            $action,
            $subscription->tenant_id ? Tenant::find($subscription->tenant_id) : null,
            array_merge($details, ['source' => 'plan_change']),
            $actorId ? User::find($actorId) : null
        ), null, false);
    }
}
