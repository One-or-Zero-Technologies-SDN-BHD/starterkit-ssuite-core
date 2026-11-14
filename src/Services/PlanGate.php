<?php

namespace Ssuite\Core\Services;

use App\Facades\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\TenantPlan;
use App\Services\PlanResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;

/**
 * Resolves the active tenant's plan and enforces resource limits + module gating.
 *
 * Usage in controllers:
 *   $gate = app(PlanGate::class);
 *   if ($redirect = $gate->enforceLimit('max_channels', Channel::query(), 'channels')) return $redirect;
 *   if ($redirect = $gate->enforceModule('broadcasts')) return $redirect;
 */
class PlanGate
{
    /**
     * Resolve the plan that gates the given tenant.
     *
     * Entitlements are WORKSPACE-scoped (billing moved from users to tenants):
     * the workspace's own `tenant_plans` row wins, which is what
     * PlanResolver::for() treats as the single source of truth. Without this,
     * a workspace-level purchase or admin override wrote tenant_plans while
     * limits/modules kept reading the OWNER's user plan — so buying a plan
     * unlocked nothing, and one person's plan leaked across every workspace
     * they owned.
     *
     * The legacy fallback below still applies when a workspace has no plan row
     * of its own: owner's user plan → product default. (Suite bundles were
     * removed: customers subscribe per app.)
     *
     * There is NO shortcut for admin-owned workspaces. That shortcut used to
     * return the highest-tier plan whenever the OWNER held admin-access, so
     * /subscription showed the real plan in its header while the usage meters
     * read their caps off a different plan entirely. Staff access is a separate
     * concern and still lives in isAdmin() (the CURRENT user, not the owner).
     */
    public function resolveFor(Tenant $tenant): Plan
    {
        $owner = $tenant->user;
        $productKey = config('app.product_key');

        // 1) The workspace's own live plan.
        $workspacePlan = PlanResolver::for($tenant);
        if ($workspacePlan && $workspacePlan->product === $productKey) {
            return $workspacePlan;
        }

        // 2) Legacy: the owner's user-level plan.
        $candidate = $owner?->plan;
        if ($candidate && $candidate->product === $productKey) {
            return $candidate;
        }

        return $this->defaultPlan();
    }

    /**
     * Resolve the plan for the currently active tenant (from session).
     */
    public function current(): Plan
    {
        $tenantId = session('active_tenant_id');
        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        if (! $tenant) {
            return $this->defaultPlan();
        }

        return $this->resolveFor($tenant);
    }

    /**
     * The tenant ids a resource count should span for the active workspace.
     *
     * Paid workspaces are counted alone — you bought that workspace's capacity.
     *
     * FREE workspaces POOL: the count spans every free workspace the same owner
     * holds. Workspace creation is unlimited, so without pooling a user could
     * take five free workspaces and get five times the free allowance — the free
     * tier would out-compete the paid one and splitting work would be the
     * rational move. Pooling makes splitting gain exactly nothing, which is what
     * lets the workspace count itself stay uncapped.
     *
     * Callers use it in place of a bare tenant filter:
     *   Product::whereIn('tenant_id', $gate->countableTenantIds($tenant))
     *
     * Costs a FIXED three queries regardless of how many workspaces the owner
     * holds. Resolving each workspace's plan individually would be O(N) on a
     * hot path — and since workspace creation is uncapped, N is now unbounded,
     * so the pooling that makes uncapping safe would be the thing that melts
     * under it. "Paid" is determined the same way PlanResolver's free-workspace
     * accounting does it: a live tenant_plans row on a priced plan.
     *
     * @return array<int, int>
     */
    public function countableTenantIds(?Tenant $tenant = null): array
    {
        $tenant ??= ($tid = session('active_tenant_id')) ? Tenant::find($tid) : null;

        if (! $tenant) {
            return [];
        }

        if (! config('billing.pool_free_tier_limits', true)) {
            return [$tenant->id];
        }

        $ownerId = $tenant->user_id;
        if (! $ownerId) {
            return [$tenant->id];
        }

        $ownedIds = Tenant::where('user_id', $ownerId)->pluck('id')->all();
        $paidTenantIds = $this->paidTenantIdsAmong($ownedIds);

        // This workspace is paid → it stands alone with its own capacity.
        if (in_array($tenant->id, $paidTenantIds, true)) {
            return [$tenant->id];
        }

        // Otherwise pool with the owner's other FREE workspaces. Paid ones are
        // excluded: they must not consume the free allowance.
        $pooled = array_values(array_diff($ownedIds, $paidTenantIds));

        return $pooled ?: [$tenant->id];
    }

    /**
     * Which of these tenants hold a live PAID plan of their own.
     *
     * @param  array<int, int>  $tenantIds
     * @return array<int, int>
     */
    private function paidTenantIdsAmong(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $paidPlanIds = Plan::forProduct()->where('price', '>', 0)->pluck('id');

        if ($paidPlanIds->isEmpty()) {
            return [];
        }

        return TenantPlan::query()
            ->whereIn('tenant_id', $tenantIds)
            ->live()
            ->whereIn('plan_id', $paidPlanIds)
            ->distinct()
            ->pluck('tenant_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** A plan is "free" when it costs nothing — the tier the pool applies to. */
    public function isFreePlan(?Plan $plan): bool
    {
        return $plan !== null && (float) ($plan->price ?? 0) <= 0;
    }

    /**
     * Enforce a numeric resource limit. Returns a redirect if the cap is reached,
     * null if the caller may proceed.
     *
     * @param  string  $planColumn  Column on plans table (e.g. 'max_channels')
     * @param  Builder|\Illuminate\Database\Query\Builder  $query  Pre-scoped count query
     * @param  string  $label  Human label for the error message
     */
    public function enforceLimit(string $planColumn, $query, string $label): ?RedirectResponse
    {
        // No admin exemption. Resource limits belong to the WORKSPACE's plan, and
        // an admin acting inside a workspace is held to it like any member — so
        // support runs under the customer's real caps ("limit reached" instead of
        // "works for me"), and an admin's own workspace is capped by its own plan.
        // Module ACCESS is gated the same way now (enforceModule/canAccess no
        // longer bypass for admins either); lift a genuine limit or a gated module
        // with the plan-override tool when support truly needs to.
        $cap = $this->cap($planColumn);

        if ($cap === null) {
            return null; // unlimited
        }

        if ($query->count() >= $cap) {
            $plan = $this->current();

            // On the pooled free tier the count spans several workspaces, so a
            // bare "limit reached" reads like a bug ("this workspace only has
            // three!"). Say where the allowance actually went.
            $pooled = config('billing.pool_free_tier_limits', true)
                && $this->isFreePlan($plan)
                && count($this->countableTenantIds()) > 1;

            $message = $pooled
                ? "You've used all {$cap} {$label} included with the {$plan->name} plan. That allowance is shared across your free workspaces — upgrade a workspace to give it its own capacity."
                : "You've reached your {$plan->name} plan limit of {$cap} {$label}. Please upgrade to add more.";

            return redirect()->back()->withInput()->with('error', $message);
        }

        return null;
    }

    /**
     * Resolve the effective cap for a plan limit column. Central metered
     * entitlements first — key "{product}.{column}" (tenant limit override →
     * plan limit row) — falling back to the legacy plans.{column} value when
     * the key is unflagged, mirroring the boolean-gate pattern.
     * NULL = unlimited (covers the legacy 99999 sentinel too).
     */
    public function cap(string $planColumn): ?int
    {
        return Feature::limit(
            config('app.product_key').'.'.$planColumn,
            fn () => $this->current()->{$planColumn},
        );
    }

    public function capFor(Tenant $tenant, string $planColumn): ?int
    {
        return Feature::for($tenant)->limit(
            config('app.product_key').'.'.$planColumn,
            fn () => $this->resolveFor($tenant)->{$planColumn},
        );
    }

    /**
     * Enforce module access. Returns a redirect if the module is not allowed,
     * null if the caller may proceed.
     */
    public function enforceModule(string $module): ?RedirectResponse
    {
        // No admin bypass — module access follows the workspace plan for admins too.
        if (! $this->moduleAllowed($module)) {
            $plan = $this->current();

            return redirect()->back()
                ->with('error', __('The :plan plan does not include this feature. Please upgrade to access it.', ['plan' => $plan->name]));
        }

        return null;
    }

    /**
     * Check if the current plan allows a module (non-redirecting, for views).
     */
    public function canAccess(string $module): bool
    {
        // No admin bypass — module visibility follows the workspace plan for admins too.
        return $this->moduleAllowed($module);
    }

    /**
     * Central feature flag first: the key "{product}.{module}" (e.g.
     * scrm.segments / scrm.broadcasts / scrm.automation), resolved for the
     * active tenant — kill switch → per-tenant override → plan-id default →
     * rollout. While no flag row exists in backoffice the key is unflagged and
     * we fall back to the legacy plans.allowed_modules check, so behaviour is
     * unchanged until central control is switched on per feature.
     */
    protected function moduleAllowed(string $module): bool
    {
        return Feature::canUse(
            config('app.product_key').'.'.$module,
            fn () => $this->current()->hasModule($module),
        );
    }

    /**
     * Check if a numeric limit has capacity remaining (non-redirecting, for views).
     */
    public function hasCapacity(string $planColumn, $query): bool
    {
        // No admin exemption — mirrors enforceLimit(): capacity reflects the
        // workspace's own plan for admins too (module ACCESS keeps its bypass).
        $cap = $this->cap($planColumn);

        if ($cap === null) {
            return true; // unlimited
        }

        return $query->count() < $cap;
    }

    /**
     * Get the current plan's value for a limit column.
     */
    public function limit(string $planColumn): int
    {
        return (int) ($this->current()->{$planColumn} ?? 0);
    }

    /**
     * Check if the active tenant has an active subscription to the given add-on.
     * Admins always pass.
     */
    public function canUseAddon(string $addonType): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $tenantId = session('active_tenant_id');
        if (! $tenantId) {
            return false;
        }

        return TenantAddon::query()
            ->forTenant($tenantId)
            ->active()
            ->where('addon_type', $addonType)
            ->exists();
    }

    /**
     * Enforce add-on access. Returns a redirect if not subscribed.
     */
    public function enforceAddon(string $addonType): ?RedirectResponse
    {
        if ($this->canUseAddon($addonType)) {
            return null;
        }

        return redirect()
            ->route('subscription.index')
            ->with('error', __('This feature requires an add-on subscription. Please activate it below.'));
    }

    protected function isAdmin(): bool
    {
        $user = auth()->user();

        return $user && $user->can('admin-access');
    }

    protected function defaultPlan(): Plan
    {
        return Plan::forProduct()->defaultPlan()->first()
            ?? Plan::forProduct()->first()
            ?? new Plan;
    }
}
