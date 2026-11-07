<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\ActivityLog;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantPlan;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\AffiliateService;
use App\Services\FeatureFlags;
use App\Services\SubscriptionGrace;
use App\Support\Billing\SubscriptionActivation;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Recover the app entitlement after a free or prepaid scheduled central transition. */
class SubscriptionTransition
{
    public const MARKER = '_ssuite_plan_transition';

    private const PENDING_REASON = 'Scheduled plan projection pending #';

    private const COMPLETED_REASON = 'Replaced by a scheduled plan change';

    /** @param Closure(?Subscription, ?PaymentTransaction): array{string, ?Subscription, ?Subscription} $transition */
    public function run(?Tenant $tenant, int $userId, ?int $paymentId, Closure $transition): string
    {
        $this->guardConnections();
        $central = DB::connection('central');
        $tenantId = $tenant ? (int) $tenant->id : null;

        return DB::transaction(function () use ($central, $tenant, $tenantId, $userId, $paymentId, $transition): string {
            return $central->transaction(function () use ($central, $tenant, $tenantId, $userId, $paymentId, $transition): string {
                // Use the same payment → local projections → central subscription order as paid activation.
                $payment = $paymentId ? PaymentTransaction::query()->forProduct()->lockForUpdate()->find($paymentId) : null;
                $before = SubscriptionActivation::projectionState($tenantId, $userId, true);
                // An existing identity also serializes new transitions when no subscription row exists yet.
                User::withTrashed()->whereKey($tenant?->user_id ?? $userId)->lockForUpdate()->firstOrFail();
                $current = $this->latest($tenantId, $userId);
                [$outcome, $subscription, $source] = $transition($current, $payment);
                if (! $subscription) {
                    return $outcome;
                }

                $details = $subscription->payment_details;
                if (! isset($details[self::MARKER])) {
                    $desired = $this->desired($subscription, $tenant);
                    if ($subscription->is($current) && $before[$tenant ? 'tenant' : 'user'] === $desired[$tenant ? 'tenant' : 'user']) {
                        return $outcome;
                    }
                    $details[self::MARKER] = Crypt::encryptString(json_encode([
                        'subscription_id' => (int) $subscription->id,
                        'product' => $subscription->product,
                        'tenant_id' => $tenantId,
                        'user_id' => (int) $subscription->user_id,
                        'source_id' => $source ? (int) $source->id : null,
                        'before' => $before,
                        'desired' => $desired,
                    ], JSON_THROW_ON_ERROR));
                    $subscription->update(['payment_details' => $details]);
                    if ($source) {
                        $source->update(['cancellation_reason' => self::PENDING_REASON.$subscription->id]);
                    }
                }

                // With an outer central transaction this waits for its actual commit too.
                $central->afterCommit(function () use ($subscription): void {
                    $this->reconcile($subscription);
                });

                return $outcome;
            });
        });
    }

    public function recoverPending(): void
    {
        $this->guardConnections();
        Subscription::query()->forProduct()->where('status', 'free')->where('billing_cycle', 'free')
            ->whereNull('payment_gateway')->whereNotNull('payment_details')
            ->chunkById(100, function ($subscriptions): void {
                foreach ($subscriptions as $subscription) {
                    rescue(function () use ($subscription): void {
                        if (isset($subscription->payment_details[self::MARKER])) {
                            $this->resume($subscription);
                        }
                    });
                }
            });

        Subscription::query()->forProduct()->where('status', 'cancelled')
            ->where('cancellation_reason', 'like', self::PENDING_REASON.'%')
            ->chunkById(100, function ($sources): void {
                foreach ($sources as $source) {
                    rescue(function () use ($source): void {
                        $id = substr($source->cancellation_reason, strlen(self::PENDING_REASON));
                        if (ctype_digit($id)) {
                            $target = $this->scope($source->tenant_id ? (int) $source->tenant_id : null, (int) $source->user_id)->find($id);
                            if ($target) {
                                $this->resume($target);
                            }
                        }
                    });
                }
            });
    }

    private function resume(Subscription $subscription): void
    {
        $central = DB::connection('central');
        if ($central->transactionLevel() === 0) {
            $this->reconcile($subscription);

            return;
        }

        $central->transaction(fn () => $central->afterCommit(fn () => $this->reconcile($subscription)));
    }

    private function reconcile(Subscription $candidate): void
    {
        $local = DB::connection();
        $central = DB::connection('central');
        $tenantId = $candidate->tenant_id ? (int) $candidate->tenant_id : null;
        $userId = (int) $candidate->user_id;

        $local->transaction(function () use ($local, $central, $candidate, $tenantId, $userId): void {
            $completion = $central->transaction(function () use ($candidate, $tenantId, $userId): ?array {
                $before = SubscriptionActivation::projectionState($tenantId, $userId, true);
                $tenant = $tenantId ? Tenant::query()->lockForUpdate()->find($tenantId) : null;
                $latest = $this->latest($tenantId, $userId);
                $subscription = $this->scope($tenantId, $userId)->lockForUpdate()->find($candidate->id);
                $cipher = $subscription?->payment_details[self::MARKER] ?? null;
                if (! is_string($cipher)) {
                    return null;
                }

                $intent = json_decode(Crypt::decryptString($cipher), true, 512, JSON_THROW_ON_ERROR);
                if ($intent['subscription_id'] !== (int) $subscription->id || $intent['product'] !== $subscription->product
                    || $intent['tenant_id'] !== $tenantId || $intent['user_id'] !== $userId) {
                    throw new LogicException('Plan projection does not match its central subscription scope.');
                }

                $current = $subscription->is($latest) && (! $tenantId || $tenant);
                if ($current) {
                    if ($tenant && (! config('billing.user_fallback') || (int) $tenant->user_id !== $userId)) {
                        $intent['desired']['user'] = null;
                    }
                    $this->project($tenantId, $userId, $intent, $before);
                }

                return [$cipher, $intent['source_id'], $current];
            });

            if ($completion !== null) {
                $local->afterCommit(fn () => $this->complete($candidate, ...$completion));
            }
        });
    }

    private function project(?int $tenantId, int $userId, array $intent, array $current): void
    {
        foreach (['tenant', 'user'] as $kind) {
            $desired = $intent['desired'][$kind];
            if ($desired === null || $current[$kind] === $desired) {
                continue;
            }
            if ($current[$kind] !== $intent['before'][$kind]) {
                // Another workspace can change the shared legacy fallback for this user.
                if ($kind === 'user' && $tenantId) {
                    continue;
                }
                throw new LogicException('Plan projection conflicts with a newer local entitlement.');
            }
            if ($kind === 'tenant') {
                TenantPlan::query()->updateOrCreate(['tenant_id' => $tenantId], $desired);
            } else {
                UserPlan::query()->updateOrCreate(['user_id' => $userId], $desired);
            }
        }
    }

    private function complete(Subscription $candidate, string $cipher, ?int $sourceId, bool $projected): void
    {
        rescue(function () use ($candidate, $cipher, $sourceId, $projected): void {
            DB::connection('central')->transaction(function () use ($candidate, $cipher, $sourceId, $projected): void {
                $subscription = Subscription::query()->forProduct()->lockForUpdate()->find($candidate->id);
                $details = $subscription?->payment_details ?? [];
                if (($details[self::MARKER] ?? null) !== $cipher) {
                    return;
                }

                $source = $sourceId ? Subscription::query()->forProduct()
                    ->where('cancellation_reason', self::PENDING_REASON.$subscription->id)->lockForUpdate()->find($sourceId) : null;
                if ($projected && $subscription->is($this->latest($subscription->tenant_id ? (int) $subscription->tenant_id : null, (int) $subscription->user_id))) {
                    if ($subscription->tenant_id) {
                        FeatureFlags::flushTenant($subscription->tenant_id);
                        SubscriptionGrace::bust($subscription->tenant_id);
                    }
                    if ($source) {
                        if ($subscription->status === 'active') {
                            AffiliateService::processSubscriptionCommission($subscription);
                        }
                        rescue(fn () => ActivityLog::record('subscription_change_applied', $subscription->tenant, [
                            'plan' => $source->plan?->name,
                            'new_plan' => $subscription->plan?->name,
                            'ends_at' => $subscription->ends_at?->toDateString(),
                            'source' => 'plan_change',
                        ]), null, false);
                    }
                }
                $source?->update(['cancellation_reason' => self::COMPLETED_REASON]);
                unset($details[self::MARKER]);
                $subscription->update(['payment_details' => $details ?: null]);
            });
        });
    }

    private function desired(Subscription $subscription, ?Tenant $tenant): array
    {
        $userId = (int) $subscription->user_id;
        $term = [
            'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
            'plan_id' => (int) $subscription->plan_id,
            'starts_at' => $subscription->starts_at?->format('Y-m-d H:i:s'),
            'status' => 'active',
        ];
        $workspace = $tenant ? $term + [
            'created_by' => $userId,
            'ends_by_choice' => false,
            'source' => $subscription->status === 'free' ? TenantPlan::SOURCE_FREE : TenantPlan::SOURCE_PURCHASE,
            'tenant_id' => (int) $tenant->id,
        ] : null;
        if ($workspace !== null) {
            ksort($workspace);
        }

        return [
            'tenant' => $workspace,
            'user' => ! $tenant || (config('billing.user_fallback') && (int) $tenant->user_id === $userId) ? $term + ['user_id' => $userId] : null,
        ];
    }

    private function latest(?int $tenantId, int $userId): ?Subscription
    {
        return $this->scope($tenantId, $userId)->whereIn('status', ['active', 'free'])->lockForUpdate()->latest('id')->first();
    }

    private function scope(?int $tenantId, int $userId): Builder
    {
        return Subscription::query()->forProduct()->when(
            $tenantId,
            fn (Builder $query) => $query->where('tenant_id', $tenantId),
            fn (Builder $query) => $query->whereNull('tenant_id')->where('user_id', $userId),
        );
    }

    private function guardConnections(): void
    {
        $local = DB::connection();
        $central = DB::connection('central');
        if ($local !== $central && $local->getPdo() === $central->getPdo()) {
            throw new LogicException('Shared-PDO aliases require one coordinated Connection object.');
        }
    }
}
