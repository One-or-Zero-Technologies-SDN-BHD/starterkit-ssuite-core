<?php

namespace Ssuite\Core\Services;

use App\Models\Tenant;
use App\Models\TenantPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The read-only grace window that follows a lapsed paid plan.
 *
 * At `ends_at` a workspace's TenantPlan stops satisfying scopeLive(), so
 * PlanResolver falls straight through to the product's default (free) plan.
 * That downgrade is silent and instant: the workspace keeps writing, now
 * against free limits, with nothing to say the plan it paid for has gone.
 *
 * This class does NOT extend entitlements — the drop to free still happens
 * exactly as before. It only identifies the window immediately after the lapse
 * (`billing.grace_days`, default 7) during which EnforceReadOnlyGrace holds the
 * workspace read-only, so the owner can still read and export everything while
 * renewal is chased. Once the window closes the workspace is an ordinary free
 * workspace and writes resume within free limits.
 *
 * Resolution is cached 60s per tenant (same TTL as PlanResolver) and is
 * fail-soft in both directions: any error — including apps with no tenants
 * table at all, e.g. backoffice — reports "not in grace", because billing
 * bookkeeping must never be able to lock a workspace out of its own data.
 *
 * Renewing clears it for free: a new live TenantPlan short-circuits the check,
 * and PlanResolver::bust() callers should call bust() here too.
 */
class SubscriptionGrace
{
    private const CACHE_TTL = 60;

    /** Length of the read-only window in days (0 disables it). */
    public static function graceDays(): int
    {
        return max(0, (int) config('billing.grace_days', 7));
    }

    /**
     * When this workspace's read-only window closes, or null when it is not in
     * one (never had a paid plan, still live, or already past the window).
     */
    public static function endsAt(Tenant|int|null $tenant): ?Carbon
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : (int) ($tenant ?: 0);

        if ($tenantId <= 0 || self::graceDays() === 0) {
            return null;
        }

        $timestamp = Cache::remember(
            self::cacheKey($tenantId),
            self::CACHE_TTL,
            fn () => self::resolve($tenantId)
        );

        // Rebuild in the app timezone. Carbon::createFromTimestamp() defaults to UTC,
        // and daysLeft() compares startOfDay() against now() (app tz) — in a +8 tz
        // (Malaysia) the UTC day boundary lands a day early, so "days left" was
        // under-reported by one. Pin the timezone so the day boundaries align.
        return $timestamp ? Carbon::createFromTimestamp($timestamp, config('app.timezone')) : null;
    }

    /** Is this workspace inside its read-only window right now? */
    public static function inGrace(Tenant|int|null $tenant): bool
    {
        return self::endsAt($tenant) !== null;
    }

    /** Whole days left in the window (0 when not in one), for banners/copy. */
    public static function daysLeft(Tenant|int|null $tenant): int
    {
        $endsAt = self::endsAt($tenant);

        return $endsAt ? max(0, (int) now()->startOfDay()->diffInDays($endsAt->copy()->startOfDay(), false)) : 0;
    }

    /** Forget a workspace's cached window (call after any plan mutation). */
    public static function bust(int|string $tenantId): void
    {
        Cache::forget(self::cacheKey((int) $tenantId));
    }

    /**
     * @return int|null unix timestamp the window closes, or null when not in one
     */
    private static function resolve(int $tenantId): ?int
    {
        try {
            $days = self::graceDays();

            // A workspace holding a live plan is current — a renewal (or an
            // admin plan override) ends the window immediately.
            if (TenantPlan::query()->where('tenant_id', $tenantId)->live()->exists()) {
                return null;
            }

            // The most recent lapse still inside the window. Rows with a NULL
            // ends_at never lapse, so they can't put a workspace in grace.
            $lapsed = TenantPlan::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', now())
                ->where('ends_at', '>', now()->subDays($days))
                ->orderByDesc('ends_at')
                ->first();

            // A plan the customer chose to end (cancelled, or switching to Free)
            // is not chased for renewal, so it never goes read-only.
            if ($lapsed?->ends_by_choice) {
                return null;
            }

            return $lapsed
                ? (int) $lapsed->ends_at->copy()->addDays($days)->getTimestamp()
                : null;
        } catch (\Throwable) {
            // No tenants/tenant_plans table (backoffice), or the DB is
            // unreachable — never hold a workspace read-only on a guess.
            return null;
        }
    }

    private static function cacheKey(int $tenantId): string
    {
        return "subscription_grace:{$tenantId}";
    }
}
