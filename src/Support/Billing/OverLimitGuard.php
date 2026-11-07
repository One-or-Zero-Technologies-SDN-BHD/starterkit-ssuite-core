<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\Tenant;
use App\Services\EntitlementUsage;
use App\Services\PlanGate;
use App\Support\Billing\OverPlanLimit;
use App\Support\Plans\PlanCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * Over-limit data is read-only, never deleted.
 *
 * When a workspace holds more of a capped resource than its plan allows (after
 * a downgrade, an expired paid plan or a lowered cap), existing records of that
 * resource can still be viewed, exported and deleted, but not edited. Creating
 * stays blocked by PlanGate::enforceLimit; restoring a soft-deleted record is
 * treated like creating one.
 *
 * Opt-in per cap column (config('plans.read_only_when_over')); the model behind
 * each column comes from EntitlementUsage. The guard only applies to changes
 * made by a signed-in user: commands, queued jobs and gateway callbacks (no
 * signed-in user) are never blocked, and system code can opt out with
 * withoutGuard(). No admin bypass, matching enforceLimit. Any lookup error fails
 * OPEN: bookkeeping must never lock a workspace out of its own data.
 */
class OverLimitGuard
{
    private static bool $paused = false;

    /** Register the `updating` listener on every guarded model. */
    public static function register(): void
    {
        foreach (self::guardedColumnsByClass() as $class => $columns) {
            $class::updating(fn (Model $record) => self::check($record, $columns));
        }
    }

    /**
     * Guarded model class => the cap columns it counts towards.
     *
     * @return array<class-string<Model>, list<string>>
     */
    public static function guardedColumnsByClass(): array
    {
        $models = rescue(fn () => app(EntitlementUsage::class)->models(), [], false);
        $byClass = [];

        foreach ((array) config('plans.read_only_when_over', []) as $column) {
            $class = $models[$column] ?? null;
            if ($class && class_exists($class) && is_a($class, Model::class, true)) {
                $byClass[$class][] = $column;
            }
        }

        return $byClass;
    }

    /** Run system code that must edit records regardless of plan limits. */
    public static function withoutGuard(callable $callback): mixed
    {
        $previous = self::$paused;
        self::$paused = true;

        try {
            return $callback();
        } finally {
            self::$paused = $previous;
        }
    }

    /**
     * @param  list<string>  $columns
     *
     * @throws OverPlanLimit
     */
    public static function check(Model $record, array $columns): void
    {
        $tenantId = (int) $record->getAttribute('tenant_id');
        if (self::$paused || ! Auth::check() || $tenantId <= 0) {
            return;
        }

        $restoring = in_array(SoftDeletes::class, class_uses_recursive($record), true)
            && $record->isDirty($record->getDeletedAtColumn())
            && $record->getOriginal($record->getDeletedAtColumn()) !== null
            && $record->getAttribute($record->getDeletedAtColumn()) === null;

        foreach ($columns as $column) {
            if (! rescue(fn () => app(EntitlementUsage::class)->counts($record, $column), false, false)) {
                continue;
            }

            $state = self::state($tenantId, $column);
            if (! $state) {
                continue;
            }

            // Restoring adds one back, like creating: blocked at the cap. Editing
            // is blocked only when strictly over it.
            if ($restoring ? $state['used'] >= $state['cap'] : $state['used'] > $state['cap']) {
                throw new OverPlanLimit($column, $state['cap'], $state['used'], self::label($column));
            }
        }
    }

    /**
     * Cap and usage for one workspace and column; null when unlimited or unknown.
     *
     * @return array{cap: int, used: int}|null
     */
    public static function state(int $tenantId, string $column): ?array
    {
        return rescue(function () use ($tenantId, $column) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant) {
                return null;
            }

            $cap = app(PlanGate::class)->capFor($tenant, $column);
            if ($cap === null) {
                return null;
            }

            $usage = app(EntitlementUsage::class)->measure($tenant, $column);
            if (! $usage || ($usage['scope'] ?? null) !== 'workspace') {
                return null;
            }

            return ['cap' => (int) $cap, 'used' => (int) $usage['used']];
        }, null, false);
    }

    /**
     * The guarded resources a workspace is over the cap on, for banners.
     *
     * @return list<array{column: string, label: string, cap: int, used: int}>
     */
    public static function overview(Tenant $tenant): array
    {
        $over = [];
        foreach (array_merge(...array_values(self::guardedColumnsByClass()) ?: [[]]) as $column) {
            $state = self::state((int) $tenant->getKey(), $column);
            if ($state && $state['used'] > $state['cap']) {
                $over[] = ['column' => $column, 'label' => self::label($column), ...$state];
            }
        }

        return $over;
    }

    private static function label(string $column): string
    {
        $label = PlanCatalog::limits()[$column] ?? str_replace(['max_', '_'], ['', ' '], $column);

        return mb_strtolower(__($label));
    }
}
