<?php

namespace Ssuite\Core\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformAccess;
use App\Support\Team\TeamRoster;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace members may delegate only permissions they hold in the target workspace.
 *
 * Runs on the team member and team role routes, so the rule holds whatever the
 * controller does. The workspace owner and platform administrators are exempt.
 * A member without the team permission for the action itself receives a 403.
 * A member who has it but reaches beyond their own role is returned to the
 * form with a message naming what they lack; a JSON request receives a 403.
 */
class EnsureWorkspaceRoleDelegation
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $tenant = $request->route('tenant');
        abort_unless($actor instanceof User && $tenant instanceof Tenant, 403);

        // Every team mutation, including owner/admin writes, shares the same
        // admission lock. Keep it through the controller's committed writes.
        $connection = $tenant->getConnection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $this->handleCurrentState($request, $next, $actor, $tenant);
        }

        $name = 'ssuite-team:'.substr(hash('sha256', $connection->getDatabaseName().'|'.$tenant->getKey()), 0, 48);
        // A dedicated session avoids coupling lock ownership to transactions
        // and to controllers that write role pivots through another connection.
        $lockConnection = 'ssuite_team_lock_'.bin2hex(random_bytes(8));
        $lock = DB::connectUsing($lockConnection, $connection->getConfig());
        $pdo = $lock->getPdo();
        $acquired = false;

        try {
            $statement = $pdo->prepare('SELECT GET_LOCK(?, 10)');
            $statement->execute([$name]);
            $acquired = (int) $statement->fetchColumn() === 1;
            abort_unless($acquired, 503, __('Team permissions are being updated. Please try again.'));

            return $this->handleCurrentState($request, $next, $actor, $tenant);
        } finally {
            try {
                if ($acquired) {
                    $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $statement->execute([$name]);
                }
            } finally {
                DB::purge($lockConnection);
            }
        }
    }

    protected function handleCurrentState(Request $request, Closure $next, User $actor, Tenant $tenant): Response
    {
        // Route binding and permission relations may have been read before a
        // competing request finished. Re-evaluate them after obtaining admission.
        $tenant->refresh();
        $role = $request->route('role');
        if ($role instanceof Role) {
            $role->refresh();
            $role->unsetRelation('permissions');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        if (class_exists(PlatformAccess::class)) {
            PlatformAccess::for()->forget();
        }

        $roster = TeamRoster::for($tenant);
        if ($roster->isOwner($actor) || $actor->can('admin-access')) {
            return $next($request);
        }

        abort_unless($roster->isMember($actor), 403);
        $held = $this->workspacePermissions($actor, $tenant);

        // Someone who may not perform the action at all is simply forbidden; the
        // explanations below are for members who manage the team.
        $ability = $this->requiredAbility($request);
        abort_if($ability !== null && ! $held->contains($ability), 403);

        $role = $request->route('role');
        if ($role instanceof Role && (int) $role->team_id === (int) $tenant->getKey()) {
            $missing = $this->missing($held, $role->permissions->pluck('name'));
            if ($missing !== []) {
                return $this->refuse($request, $tenant, 'role', $missing, $role->name);
            }
        }

        $member = $request->route('user');
        if ($member instanceof User && $roster->isMember($member) && ! $roster->isOwner($member)) {
            $roleIds = $roster->assignments()[(int) $member->getKey()] ?? [];
            foreach ($roster->roles()->whereIn('id', $roleIds) as $currentRole) {
                $missing = $this->missing($held, $currentRole->permissions->pluck('name'));
                if ($missing !== []) {
                    return $this->refuse($request, $tenant, 'member', $missing, $currentRole->name);
                }
            }
        }

        if ($request->routeIs('tenants.users.store', 'tenants.users.update') && is_string($name = $request->input('role'))) {
            // Match the database's name comparison, including case-insensitive collations.
            $selectedRole = config('permission.models.role')::query()
                ->where('team_id', $tenant->getKey())->where('guard_name', 'web')->where('name', $name)
                ->with('permissions')->first();
            if ($selectedRole) {
                $missing = $this->missing($held, $selectedRole->permissions->pluck('name'));
                if ($missing !== []) {
                    return $this->refuse($request, $tenant, 'assign', $missing, $selectedRole->name);
                }
            }
        }

        if ($request->routeIs('tenants.roles.store', 'tenants.roles.update') && is_array($permissions = $request->input('permissions'))) {
            // Leave invalid names and payload types to the controller's validation.
            $names = array_filter($permissions, is_string(...));
            $requested = config('permission.models.permission')::query()->where('guard_name', 'web')->whereIn('name', $names)->pluck('name');
            $missing = $this->missing($held, $requested);
            if ($missing !== []) {
                return $this->refuse($request, $tenant, 'permissions', $missing);
            }
        }

        return $next($request);
    }

    /** The team permission the route's own action needs, as the Team controllers check it. */
    protected function requiredAbility(Request $request): ?string
    {
        return match (true) {
            $request->routeIs('tenants.users.store') => TeamRoster::ability('members', 'create'),
            $request->routeIs('tenants.users.update') => TeamRoster::ability('members', 'edit'),
            $request->routeIs('tenants.users.destroy') => TeamRoster::ability('members', 'delete'),
            $request->routeIs('tenants.roles.store', 'tenants.roles.duplicate') => TeamRoster::ability('roles', 'create'),
            $request->routeIs('tenants.roles.update') => TeamRoster::ability('roles', 'edit'),
            $request->routeIs('tenants.roles.destroy') => TeamRoster::ability('roles', 'delete'),
            default => null,
        };
    }

    /**
     * Stop the request. `$reason` is `permissions` (granting more than the member
     * holds on a role), `assign` (handing out a stronger role), `member` (changing
     * or removing someone stronger) or `role` (changing, deleting or copying a
     * stronger role).
     *
     * @param  list<string>  $missing
     */
    protected function refuse(Request $request, Tenant $tenant, string $reason, array $missing, ?string $subject = null): Response
    {
        $message = $this->message($request, $reason, $missing, $subject);

        if ($request->expectsJson()) {
            abort(403, $message);
        }

        return match ($reason) {
            'permissions' => back()->withErrors(['permissions' => $message])->withInput(),
            'assign' => back()->withErrors(['role' => $message])->withInput(),
            'member' => redirect()->route('tenants.users.index', $tenant)->with('error', $message),
            default => redirect()->route('tenants.roles.index', $tenant)->with('error', $message),
        };
    }

    /** @param  list<string>  $missing */
    protected function message(Request $request, string $reason, array $missing, ?string $subject): string
    {
        $list = $this->describe($missing);

        return match ($reason) {
            'permissions' => __('You can only give permissions you have yourself. Untick: :list.', ['list' => $list]),
            'assign' => __('You can’t give someone a role that can do more than you. :role also allows: :list.', ['role' => (string) $subject, 'list' => $list]),
            'member' => __('You can’t change someone whose role can do more than yours. Ask the workspace owner.'),
            default => $request->routeIs('tenants.roles.duplicate')
                ? __('You can’t copy this role: it can do things your own role can’t (:list).', ['list' => $list])
                : __('You can’t change this role: it can do things your own role can’t (:list).', ['list' => $list]),
        };
    }

    /**
     * Permission names in plain words for a message. Override to use the app's own labels.
     *
     * @param  list<string>  $permissions
     */
    protected function describe(array $permissions, int $limit = 5): string
    {
        $labels = array_map(fn (string $name): string => ucfirst(str_replace(['-', '_'], ' ', $name)), $permissions);
        $shown = array_slice($labels, 0, $limit);
        $more = count($labels) - count($shown);

        return implode(', ', $shown).($more > 0 ? ' '.__('and :count more', ['count' => $more]) : '');
    }

    /**
     * @param  Collection<int, string>  $held
     * @param  Collection<int, string>  $requested
     * @return list<string>
     */
    /**
     * The roles the actor may hand out in this workspace: those that allow
     * nothing beyond what the actor holds. The owner and platform
     * administrators may hand out every role.
     *
     * @template TRole of \Spatie\Permission\Models\Role
     *
     * @param  Collection<int, TRole>  $roles
     * @return Collection<int, TRole>
     */
    public function rolesActorMayHandOut(User $actor, Tenant $tenant, Collection $roles): Collection
    {
        if (TeamRoster::for($tenant)->isOwner($actor) || $actor->can('admin-access')) {
            return $roles->values();
        }

        $held = $this->workspacePermissions($actor, $tenant);
        $roles->each(fn ($role) => $role->loadMissing('permissions'));

        return $roles->filter(fn ($role) => $this->missing($held, $role->permissions->pluck('name')) === [])->values();
    }

    protected function missing(Collection $held, Collection $requested): array
    {
        return $requested->diff($held)->unique()->values()->all();
    }

    /** @return Collection<int, string> */
    protected function workspacePermissions(User $user, Tenant $tenant): Collection
    {
        $previous = getPermissionsTeamId();
        setPermissionsTeamId($tenant->getKey());
        $user->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $user->getAllPermissions()->pluck('name');
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
