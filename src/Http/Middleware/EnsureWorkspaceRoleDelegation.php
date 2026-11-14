<?php

namespace Ssuite\Core\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Team\TeamRoster;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/** Workspace members may delegate only permissions they hold in the target workspace. */
class EnsureWorkspaceRoleDelegation
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $tenant = $request->route('tenant');
        abort_unless($actor instanceof User && $tenant instanceof Tenant, 403);

        $roster = TeamRoster::for($tenant);
        if ($roster->isOwner($actor) || $actor->can('admin-access')) {
            return $next($request);
        }

        abort_unless($roster->isMember($actor), 403);
        $held = $this->workspacePermissions($actor, $tenant);

        $role = $request->route('role');
        if ($role instanceof Role && (int) $role->team_id === (int) $tenant->getKey()) {
            $this->assertCovered($held, $role->permissions->pluck('name'));
        }

        $member = $request->route('user');
        if ($member instanceof User && $roster->isMember($member) && ! $roster->isOwner($member)) {
            $roleIds = $roster->assignments()[(int) $member->getKey()] ?? [];
            foreach ($roster->roles()->whereIn('id', $roleIds) as $currentRole) {
                $this->assertCovered($held, $currentRole->permissions->pluck('name'));
            }
        }

        if ($request->routeIs('tenants.users.store', 'tenants.users.update') && is_string($name = $request->input('role'))) {
            // Match the database's name comparison, including case-insensitive collations.
            $selectedRole = config('permission.models.role')::query()
                ->where('team_id', $tenant->getKey())->where('guard_name', 'web')->where('name', $name)
                ->with('permissions')->first();
            if ($selectedRole) {
                $this->assertCovered($held, $selectedRole->permissions->pluck('name'));
            }
        }

        if ($request->routeIs('tenants.roles.store', 'tenants.roles.update') && is_array($permissions = $request->input('permissions'))) {
            // Leave invalid names and payload types to the controller's validation.
            $names = array_filter($permissions, is_string(...));
            $requested = config('permission.models.permission')::query()->where('guard_name', 'web')->whereIn('name', $names)->pluck('name');
            $this->assertCovered($held, $requested);
        }

        return $next($request);
    }

    private function workspacePermissions(User $user, Tenant $tenant): Collection
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

    private function assertCovered(Collection $held, Collection $requested): void
    {
        abort_if($requested->diff($held)->isNotEmpty(), 403, __('You can only delegate permissions you hold in this workspace.'));
    }
}
