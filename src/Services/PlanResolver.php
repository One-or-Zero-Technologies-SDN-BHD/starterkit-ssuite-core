<?php

namespace Ssuite\Core\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for "which Plan does this workspace have?".
 *
 * Keystone of the user→tenant billing migration (see
 * project-saas/TENANT-BILLING-PLAN.md): every entitlement consumer — feature
 * flags, module gates, limit checks, upgrade CTAs — resolves through here, so
 * flipping billing models is a resolution-order change, not a scavenger hunt.
 *
 * Resolution order:
 *   1. tenant_plans — the workspace's own live plan row          (target state)
 *   2. owner's user_plans row                     [billing.user_fallback only]
 *   3. owner's live central 'bundle' subscription covering this product
 *   4. the product's default plan
 *
 * Plan ids are cached 60s per tenant (same TTL as FeatureFlags). Call
 * PlanResolver::bust($tenantId) after mutating a workspace's plan;
 * FeatureFlags::flushTenant() does this for you.
 */
class PlanResolver
{
    private const CACHE_TTL = 60;

    /** The workspace's effective Plan (null only when no tenant / no plans exist). */
    public static function for(Tenant|int|null $tenant): ?Plan
    {
        $planId = self::planId($tenant);

        return $planId ? Plan::find($planId) : null;
    }

    /** Cached effective plan id for a workspace. */
    public static function planId(Tenant|int|null $tenant): ?int
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : (int) ($tenant ?: 0);
        if ($tenantId <= 0) {
            return null;
        }

        return Cache::remember(
            self::cacheKey($tenantId),
            self::CACHE_TTL,
            fn () => self::resolve($tenant instanceof Tenant ? $tenant : $tenantId)
        ) ?: null;
    }

    /** Forget a workspace's cached plan (call after any plan mutation). */
    public static function bust(int|string $tenantId): void
    {
        Cache::forget(self::cacheKey((int) $tenantId));
    }

    /**
     * Bootstrap a workspace onto a plan at creation time: a tenant_plans row
     * + a central `free` subscription bound to the tenant. Order: claim the
     * owner's unbound live sub → the explicitly chosen FREE plan ($chosen,
     * e.g. picked in the create-workspace wizard) → the product default.
     * Idempotent and best-effort — never blocks workspace creation.
     */
    public static function bootstrapFreePlan(Tenant $tenant, ?int $purchaserId = null, ?Plan $chosen = null): void
    {
        try {
            // Claim-at-creation: an owner who paid BEFORE having a workspace
            // (or whose sub predates tenant billing) carries a live, unbound
            // subscription — their new workspace claims it instead of
            // dropping to the default plan.
            $ownerId = $purchaserId ?? $tenant->user_id;
            $unclaimed = $ownerId
                ? Subscription::forProduct()
                    ->whereNull('tenant_id')
                    ->where('user_id', $ownerId)
                    ->activeOrFree()
                    ->latest('starts_at')
                    ->first()
                : null;

            if ($unclaimed && self::isOrganizationFunded($unclaimed)) {
                $unclaimed = null; // org-funded subs are never claimed by a workspace (ADR-001)
            }

            if ($unclaimed) {
                $claimPlan = $unclaimed->plan_id ? Plan::find($unclaimed->plan_id) : null;
                if ($claimPlan && $claimPlan->product === config('app.product_key')) {
                    $unclaimed->update(['tenant_id' => $tenant->id]);

                    TenantPlan::firstOrCreate(
                        ['tenant_id' => $tenant->id],
                        [
                            'plan_id' => $claimPlan->id,
                            'status' => 'active',
                            'source' => TenantPlan::SOURCE_PURCHASE,
                            'starts_at' => $unclaimed->starts_at ?? now(),
                            'ends_at' => $unclaimed->ends_at,
                            'created_by' => $ownerId,
                        ]
                    );

                    self::bust($tenant->id);

                    return;
                }
            }

            // Explicitly chosen free plan (wizard) beats the product default.
            $plan = ($chosen && $chosen->product === config('app.product_key')
                    && ! $chosen->is_contact_only && (float) $chosen->price <= 0)
                ? $chosen
                : Plan::forProduct()->defaultPlan()->first();

            if (! $plan) {
                return;
            }

            TenantPlan::firstOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'source' => TenantPlan::SOURCE_FREE,
                    'starts_at' => now(),
                    'ends_at' => null,
                    'created_by' => $purchaserId,
                ]
            );

            $hasLiveSub = Subscription::forProduct()
                ->where('tenant_id', $tenant->id)
                ->activeOrFree()
                ->exists();

            if (! $hasLiveSub) {
                Subscription::create([
                    'user_id' => $purchaserId ?? $tenant->user_id,
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'product' => config('app.product_key'),
                    'status' => 'free',
                    'amount' => 0,
                    'billing_cycle' => 'free',
                    'starts_at' => now(),
                    'ends_at' => null,
                ]);
            }

            self::bust($tenant->id);
        } catch (\Throwable $e) {
            Log::warning('PlanResolver::bootstrapFreePlan failed: '.$e->getMessage(), [
                'tenant_id' => $tenant->id,
            ]);
        }
    }

    /**
     * Has this user exhausted their free-workspace quota?
     * A workspace counts against the quota unless it holds a live PAID plan.
     */
    public static function freeWorkspaceCapReached($user): bool
    {
        $cap = (int) config('billing.free_workspaces_per_user', 2);
        if ($cap <= 0) {
            return false; // 0 or negative = unlimited (explicitly opt-in)
        }

        $ownedIds = $user->ownedTenants()->pluck('id');
        if ($ownedIds->count() < $cap) {
            return false;
        }

        $paidPlanIds = Plan::forProduct()->where('price', '>', 0)->pluck('id');

        $paidWorkspaces = TenantPlan::query()
            ->whereIn('tenant_id', $ownedIds)
            ->live()
            ->whereIn('plan_id', $paidPlanIds)
            ->distinct()
            ->count('tenant_id');

        return ($ownedIds->count() - $paidWorkspaces) >= $cap;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Is this subscription funded by the suite control plane (organization
     * billing account or an active allocation)? Such subs belong to the org
     * layer (ADR-001) and must never be bound/claimed as workspace subs.
     * Tables/columns may not exist yet in every app — probe defensively.
     */
    private static function isOrganizationFunded(Subscription $sub): bool
    {
        try {
            $central = Schema::connection('central');

            if ($central->hasTable('subscription_allocations')
                && DB::connection('central')
                    ->table('subscription_allocations')->where('subscription_id', $sub->id)->exists()) {
                return true;
            }

            if ($sub->getAttribute('billing_account_id')
                && $central->hasTable('billing_accounts')
                && DB::connection('central')
                    ->table('billing_accounts')
                    ->where('id', $sub->getAttribute('billing_account_id'))
                    ->where('account_scope', 'organization')
                    ->exists()) {
                return true;
            }
        } catch (\Throwable) {
            // fail open: treat as user-tied when the probe is impossible
        }

        return false;
    }

    private static function cacheKey(int $tenantId): string
    {
        return "billing:tenant-plan:{$tenantId}";
    }

    private static function resolve(Tenant|int $tenant): ?int
    {
        try {
            $tenant = $tenant instanceof Tenant ? $tenant : Tenant::find($tenant);
        } catch (\Throwable) {
            return null; // no tenants table in this app (e.g. backoffice)
        }

        if (! $tenant) {
            return null;
        }

        $productKey = config('app.product_key');

        // 1. The workspace's own plan.
        $tenantPlan = TenantPlan::query()
            ->where('tenant_id', $tenant->id)
            ->live()
            ->first();

        if ($tenantPlan) {
            $plan = Plan::find($tenantPlan->plan_id);
            if ($plan && $plan->product === $productKey) {
                return (int) $plan->id;
            }
        }

        // 2. Transition fallback: the owner's user-level plan.
        if (config('billing.user_fallback')) {
            $ownerPlan = $tenant->user?->plan;
            if ($ownerPlan && $ownerPlan->product === $productKey) {
                return (int) $ownerPlan->id;
            }

            // 3. Legacy user-level bundle covering this product.
            if ($tenant->user_id) {
                $bundleSub = Subscription::query()
                    ->with('plan')
                    ->where('user_id', $tenant->user_id)
                    ->where('product', 'bundle')
                    ->activeOrFree()
                    ->latest('starts_at')
                    ->first();

                if ($bundleSub && in_array($productKey, $bundleSub->plan?->bundle_products ?? [], true)) {
                    return (int) $bundleSub->plan_id;
                }
            }
        }

        // 4. The product's default plan.
        return Plan::forProduct()->defaultPlan()->first()?->id;
    }
}
