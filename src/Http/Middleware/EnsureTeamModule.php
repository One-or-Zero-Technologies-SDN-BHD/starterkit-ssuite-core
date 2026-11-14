<?php

namespace Ssuite\Core\Http\Middleware;

use App\Facades\Feature;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlanGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Team pages (members and roles) need the team-management plan module.
 *
 * Runs on the team member and team role routes. The plan is the workspace's
 * own plan, so a member sees what the workspace is entitled to, not what their
 * personal plan says. Platform administrators are exempt. Leaving a workspace
 * is not behind this gate: a member can always leave.
 */
class EnsureTeamModule
{
    public const MODULE = 'team-management';

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $tenant = $request->route('tenant');
        abort_unless($actor instanceof User && $tenant instanceof Tenant, 403);

        if ($actor->can('admin-access') || $this->allowed($tenant)) {
            return $next($request);
        }

        abort(403, __('Team management is not available on your current plan.'));
    }

    /**
     * Whether the workspace's plan includes team management.
     */
    protected function allowed(Tenant $tenant): bool
    {
        return Feature::canUse(
            config('app.product_key').'.'.static::MODULE,
            fn () => app(PlanGate::class)->resolveFor($tenant)->hasModule(static::MODULE),
        );
    }
}
