<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\TenantPlan;
use App\Models\UserPlan;
use App\Services\AffiliateService;
use App\Services\CouponService;
use App\Services\FeatureFlags;
use App\Services\SubscriptionGrace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Central commits first; local projections remain recoverable until their actual commit. */
class SubscriptionActivation
{
    public const MARKER = '_ssuite_subscription_projection';

    private const TENANT_FIELDS = ['tenant_id', 'plan_id', 'status', 'source', 'starts_at', 'ends_at', 'ends_by_choice', 'created_by'];

    private const USER_FIELDS = ['user_id', 'plan_id', 'status', 'starts_at', 'ends_at'];

    /** @var array<callable>|null */
    private ?array $callbacks = null;

    public static function for(): self
    {
        if (! app()->bound(static::class)) {
            app()->scoped(static::class);
        }

        return app(static::class);
    }

    public function run(PaymentTransaction $payment, callable $operation): mixed
    {
        $local = DB::connection();
        $central = DB::connection('central');
        if ($local->getPdo() === $central->getPdo()) {
            // Distinct Laravel aliases keep separate transaction counters and
            // may collide on both BEGIN and savepoint names. Only the same
            // Connection object can provide this genuinely atomic branch.
            if ($local !== $central) {
                throw new LogicException('Shared-PDO aliases require one coordinated Connection object.');
            }
            if ($this->callbacks !== null) {
                throw new LogicException('Subscription activation cannot be reentered.');
            }
            $this->callbacks = [];
            try {
                return $local->transaction(function () use ($local, $payment, $operation): mixed {
                    $row = PaymentTransaction::query()->forProduct()->lockForUpdate()->findOrFail($payment->getKey());
                    if ($row->status === 'success') {
                        return null;
                    }
                    $result = $operation();
                    $callbacks = $this->callbacks;
                    $this->callbacks = null;
                    $local->afterCommit(function () use ($callbacks): void {
                        foreach ($callbacks as $callback) {
                            $callback();
                        }
                    });

                    return $result;
                });
            } finally {
                $this->callbacks = null;
            }
        }
        if ($central->transactionLevel() !== 0 || $this->callbacks !== null) {
            throw new LogicException('Independent subscription activation must own the central commit boundary.');
        }

        $this->callbacks = [];
        try {
            return $local->transaction(function () use ($central, $local, $payment, $operation): mixed {
                $cipher = null;
                $result = $central->transaction(function () use ($payment, $operation, &$cipher): mixed {
                    $row = PaymentTransaction::query()->forProduct()->lockForUpdate()->findOrFail($payment->getKey());
                    $beforeStatus = $row->status;
                    if ($beforeStatus === 'success') {
                        return null;
                    }
                    // Locking current reads serialize workspace projections and
                    // avoid a stale consistent-read baseline after another sale.
                    $before = $this->projections($row, true);
                    $result = $operation();
                    $row->refresh();
                    if ($beforeStatus !== 'success' && $row->status === 'success') {
                        $subscription = $this->linkedSubscription($row);
                        if (! $subscription) {
                            throw new LogicException('A confirmed subscription activation must retain its central subscription link.');
                        }
                        $cipher = Crypt::encryptString(json_encode([
                            'version' => 1,
                            'token' => (string) Str::uuid(),
                            'payment_id' => (int) $row->id,
                            'product' => $row->product,
                            'tenant_id' => $row->tenant_id === null ? null : (int) $row->tenant_id,
                            'user_id' => (int) $row->user_id,
                            'subscription_id' => (int) $subscription->id,
                            'before' => $before,
                            'desired' => $this->projections($row, true),
                        ], JSON_THROW_ON_ERROR));
                        $response = $row->gateway_response ?? [];
                        $response[self::MARKER] = $cipher;
                        $row->update(['gateway_response' => $response]);
                    }

                    return $result;
                });
                // Register only after central has committed: Laravel's transaction
                // callback manager otherwise selects the innermost central record.
                $callbacks = $this->callbacks;
                $this->callbacks = null;
                $local->afterCommit(function () use ($payment, $cipher, $callbacks): void {
                    foreach ($callbacks as $callback) {
                        $callback();
                    }
                    if ($cipher !== null) {
                        $this->complete($payment->id, $cipher);
                    }
                });

                return $result;
            });
        } finally {
            $this->callbacks = null;
        }
    }

    /** Defer notifications/cache effects until the independent local commit actually succeeds. */
    public function afterCommit(callable $callback): void
    {
        if ($this->callbacks !== null) {
            $this->callbacks[] = $callback;

            return;
        }
        $local = DB::connection();
        if ($local->getPdo() === DB::connection('central')->getPdo() || $local->transactionLevel() === 0) {
            $callback();

            return;
        }
        $local->afterCommit($callback);
    }

    public function pending(PaymentTransaction $payment): bool
    {
        $row = PaymentTransaction::query()->forProduct()->find($payment->getKey());

        return $row?->status === 'success' && $this->intent($row) !== null;
    }

    /** Returns none, superseded or recovered; never creates a new central sale or provider charge. */
    public function reconcile(PaymentTransaction $payment): string
    {
        $local = DB::connection();
        $central = DB::connection('central');
        if ($local !== $central && $local->getPdo() === $central->getPdo()) {
            throw new LogicException('Shared-PDO aliases require one coordinated Connection object.');
        }
        if ($central->transactionLevel() !== 0 && $local->getPdo() !== $central->getPdo()) {
            throw new LogicException('Reconciliation must own the central commit boundary.');
        }

        return $local->transaction(function () use ($local, $central, $payment): string {
            $cipher = null;
            $subscription = null;
            $result = $central->transaction(function () use ($payment, &$cipher, &$subscription): string {
                $row = PaymentTransaction::query()->forProduct()->lockForUpdate()->find($payment->getKey());
                $intent = $row?->status === 'success' ? $this->intent($row) : null;
                if (! $intent) {
                    return 'none';
                }
                $cipher = $row->gateway_response[self::MARKER];
                $subscription = $this->linkedSubscription($row);
                if (! $subscription || (int) $subscription->id !== $intent['subscription_id'] || ! $this->latest($row, $subscription)) {
                    $subscription = null;

                    return 'superseded';
                }
                $entitlement = $intent['desired'][$row->tenant_id ? 'tenant' : 'user'];
                if ($row->subscription_id && ($entitlement['plan_id'] ?? null) !== (int) $subscription->plan_id) {
                    $subscription = null;

                    return 'superseded';
                }
                $current = $this->projections($row, true);
                $skipUser = false;
                foreach (['tenant', 'user'] as $kind) {
                    if ($intent['before'][$kind] === $intent['desired'][$kind]) {
                        continue;
                    }
                    if ($current[$kind] !== $intent['before'][$kind] && $current[$kind] !== $intent['desired'][$kind]) {
                        // A different workspace may have changed the owner's
                        // legacy fallback without superseding this tenant's plan.
                        if ($kind === 'user' && $row->tenant_id) {
                            $skipUser = true;

                            continue;
                        }
                        $subscription = null;

                        return 'superseded';
                    }
                }
                foreach (['tenant', 'user'] as $kind) {
                    $desired = $intent['desired'][$kind];
                    if ($desired === null || $intent['before'][$kind] === $desired || $current[$kind] === $desired || ($kind === 'user' && $skipUser)) {
                        continue;
                    }
                    if ($kind === 'tenant') {
                        TenantPlan::query()->updateOrCreate(['tenant_id' => $row->tenant_id], $desired);
                    } else {
                        UserPlan::query()->updateOrCreate(['user_id' => $row->user_id], $desired);
                    }
                }

                return 'recovered';
            });
            if ($cipher !== null) {
                $local->afterCommit(function () use ($payment, $cipher, $subscription, $result): void {
                    if ($result === 'recovered') {
                        $this->recoveredEffects($payment, $subscription);
                    }
                    $this->complete($payment->id, $cipher);
                });
            }

            return $result;
        });
    }

    /** Only identifiers, entitlement flags and database-normalized dates; no provider secrets or personal fields. */
    private function projections(PaymentTransaction $payment, bool $lock = false): array
    {
        return self::projectionState($payment->tenant_id ? (int) $payment->tenant_id : null, (int) $payment->user_id, $lock);
    }

    public static function projectionState(?int $tenantId, int $userId, bool $lock = false): array
    {
        $tenant = $tenantId ? TenantPlan::query()->useWritePdo()->where('tenant_id', $tenantId) : null;
        $user = UserPlan::query()->useWritePdo()->where('user_id', $userId);
        if ($lock) {
            $tenant?->lockForUpdate();
            $user->lockForUpdate();
        }

        return [
            'tenant' => self::snapshot($tenant?->first(), self::TENANT_FIELDS),
            'user' => self::snapshot($user->first(), self::USER_FIELDS),
        ];
    }

    private static function snapshot(?Model $model, array $fields): ?array
    {
        if (! $model) {
            return null;
        }
        $values = array_intersect_key($model->getAttributes(), array_flip($fields));
        foreach ($values as $key => &$value) {
            if ($value !== null && (str_ends_with($key, '_id') || $key === 'plan_id')) {
                $value = (int) $value;
            } elseif ($key === 'ends_by_choice') {
                $value = (bool) $value;
            } elseif ($value !== null && in_array($key, ['starts_at', 'ends_at'], true)) {
                // MySQL and SQLite may expose different fractional-second precision.
                $value = substr((string) $value, 0, 19);
            }
        }
        unset($value);
        ksort($values);

        return $values;
    }

    private function intent(PaymentTransaction $payment): ?array
    {
        $cipher = $payment->gateway_response[self::MARKER] ?? null;
        if (! is_string($cipher)) {
            return null;
        }
        try {
            $intent = json_decode(Crypt::decryptString($cipher), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($intent) || ($intent['version'] ?? null) !== 1 || ($intent['payment_id'] ?? null) !== (int) $payment->id
            || ($intent['product'] ?? null) !== config('app.product_key') || $payment->product !== config('app.product_key')
            || ($intent['tenant_id'] ?? null) !== ($payment->tenant_id === null ? null : (int) $payment->tenant_id)
            || ($intent['user_id'] ?? null) !== (int) $payment->user_id
            || ! is_array($intent['before'] ?? null) || ! is_array($intent['desired'] ?? null)) {
            return null;
        }

        foreach (['before', 'desired'] as $phase) {
            foreach (['tenant', 'user'] as $kind) {
                if (! array_key_exists($kind, $intent[$phase]) || ($intent[$phase][$kind] !== null && ! is_array($intent[$phase][$kind]))) {
                    return null;
                }
            }
        }

        return $intent;
    }

    private function linkedSubscription(PaymentTransaction $payment): ?Subscription
    {
        return Subscription::query()->forProduct()
            ->where('user_id', $payment->user_id)
            ->when($payment->tenant_id, fn ($q) => $q->where('tenant_id', $payment->tenant_id), fn ($q) => $q->whereNull('tenant_id'))
            ->where('status', 'active')
            ->when($payment->subscription_id, fn ($q) => $q->whereKey($payment->subscription_id)->when($payment->plan_id, fn ($q) => $q->where('plan_id', $payment->plan_id)), fn ($q) => $q->where('scheduled_transaction_id', $payment->id))
            ->first();
    }

    private function latest(PaymentTransaction $payment, Subscription $subscription): bool
    {
        return (int) Subscription::query()->forProduct()
            ->whereIn('status', ['active', 'free'])
            ->when($payment->tenant_id, fn ($q) => $q->where('tenant_id', $payment->tenant_id), fn ($q) => $q->whereNull('tenant_id')->where('user_id', $payment->user_id))
            ->orderByDesc('id')->value('id') === (int) $subscription->id;
    }

    private function complete(int $id, string $cipher): void
    {
        try {
            DB::connection('central')->transaction(function () use ($id, $cipher): void {
                $row = PaymentTransaction::query()->forProduct()->lockForUpdate()->find($id);
                $response = $row?->gateway_response ?? [];
                if (($response[self::MARKER] ?? null) !== $cipher) {
                    return;
                }
                unset($response[self::MARKER]);
                $row->update(['gateway_response' => $response]);
            });
        } catch (Throwable $exception) {
            Log::warning('Subscription projection completion remains pending', ['payment_transaction_id' => $id, 'exception_class' => $exception::class]);
        }
    }

    private function recoveredEffects(PaymentTransaction $payment, ?Subscription $subscription): void
    {
        if ($payment->tenant_id) {
            FeatureFlags::flushTenant((int) $payment->tenant_id);
            SubscriptionGrace::bust((int) $payment->tenant_id);
        }
        rescue(fn () => AffiliateService::attributeCoupon($payment), null, false);
        if ($subscription && $payment->subscription_id) {
            rescue(fn () => AffiliateService::processSubscriptionCommission($subscription), null, false);
        }
        if ($payment->coupon_id) {
            rescue(fn () => app(CouponService::class)->redeem($payment), null, false);
        }
    }
}
