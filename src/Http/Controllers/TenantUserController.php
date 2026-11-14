<?php

namespace Ssuite\Core\Http\Controllers;

use App\Concerns\LogsActivity;
use App\DataTables\TenantUsersDataTable;
use App\Http\Controllers\Controller;
use App\Http\Controllers\TenantRoleController;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPlan;
use App\Notifications\MemberPermissionChanged;
use App\Notifications\NewMemberJoinedWorkspace;
use App\Services\WorkspaceSeatGuard;
use App\Support\Team\TeamRoster;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Ssuite\Core\Http\Middleware\EnsureWorkspaceRoleDelegation;
use Ssuite\Core\Support\Team\InvitationDelivery;

class TenantUserController extends Controller
{
    use LogsActivity;

    /**
     * Display a listing of the resource.
     */
    public function index(Tenant $tenant, TenantUsersDataTable $dataTable)
    {
        $isOwner = $tenant->user_id === Auth::id();
        $isAdmin = Auth::user()->hasRole('Administrator') || Auth::user()->can('admin-access');
        $this->authorizeWorkspace($tenant);

        if (! $isAdmin && ! $isOwner) {
            $this->authorizeTeamAbility($tenant, 'list');
        }

        $roster = TeamRoster::for($tenant);
        $stats = $roster->stats();
        $seats = $roster->seats();
        $roles = $roster->roles();

        return $dataTable->render('tenants.users.index', compact('tenant', 'stats', 'seats', 'roles'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function checkEmail(Request $request, Tenant $tenant)
    {
        $isOwner = $tenant->user_id === Auth::id();
        $isAdmin = Auth::user()->hasRole('Administrator') || Auth::user()->can('admin-access');
        $this->authorizeWorkspace($tenant);

        if (! $isAdmin && ! $isOwner) {
            $this->authorizeTeamAbility($tenant, 'create');
        }

        $email = trim((string) $request->query('email', ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'exists' => false,
                'in_this_tenant' => false,
                'can_link' => false,
                'name' => null,
                'message' => null,
            ]);
        }

        $existing = User::withTrashed()->where('email', $email)->first();
        if (! $existing) {
            return response()->json([
                'exists' => false,
                'in_this_tenant' => false,
                'can_link' => false,
                'name' => null,
                'message' => 'No central account — a new user will be created and invited.',
            ]);
        }

        $inThisTenant = TeamRoster::for($tenant)->isMember($existing);

        return response()->json([
            'exists' => true,
            'in_this_tenant' => $inThisTenant,
            'can_link' => ! $inThisTenant,
            'name' => $existing->name,
            'trashed' => (bool) $existing->trashed(),
            'message' => $inThisTenant
                ? "{$existing->name} is already a member of this workspace."
                : ($existing->trashed()
                    ? "Existing central account (soft-deleted previously). Saving will restore and attach {$existing->name} to this workspace."
                    : "Existing user found — saving will attach {$existing->name} to this workspace."),
        ]);
    }

    public function create(Tenant $tenant)
    {
        $isOwner = $tenant->user_id === Auth::id();
        $isAdmin = Auth::user()->hasRole('Administrator') || Auth::user()->can('admin-access');
        $this->authorizeWorkspace($tenant);

        if (! $isAdmin && ! $isOwner) {
            $this->authorizeTeamAbility($tenant, 'create');
        }

        $roles = Role::where('team_id', $tenant->id)->get();

        if ($roles->isEmpty()) {
            return redirect()->route('tenants.roles.index', $tenant)
                ->with('error', 'Please create at least one role (e.g. Manager, Editor) before inviting members to your team.');
        }

        $roles = $this->offeredRoles($tenant, $roles);

        if ($roles->isEmpty()) {
            return redirect()->route('tenants.users.index', $tenant)
                ->with('error', __('Every role here can do things your own role can’t, so you can’t give any of them out. Ask the workspace owner.'));
        }

        $roster = TeamRoster::for($tenant);
        $seats = $roster->seats();
        $roleCards = $this->roleCards($roster, $roles);

        return view('tenants.users.create', compact('tenant', 'roles', 'seats', 'roleCards'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Tenant $tenant)
    {
        $recipient = null;
        $response = app(WorkspaceSeatGuard::class)->run(
            $tenant,
            function (Tenant $locked) use ($request, &$recipient) {
                return $this->storeWithinCapacity($request, $locked, $recipient);
            },
            function (Tenant $tenant) {
                $isOwner = $tenant->user_id === Auth::id();
                $isAdmin = Auth::user()->hasRole('Administrator') || Auth::user()->can('admin-access');
                $this->authorizeWorkspace($tenant);

                if (! $isAdmin && ! $isOwner) {
                    $this->authorizeTeamAbility($tenant, 'create');
                }
            },
        );

        // Email delivery happens after the capacity transaction, never before
        // the membership and role have been saved.
        if ($recipient && ! InvitationDelivery::send($recipient, $tenant)) {
            session()->forget('success');

            return $response->with('warning', __('The team invitation was saved, but the email could not be sent. Use Resend invite to try again.'));
        }

        return $response;
    }

    protected function storeWithinCapacity(Request $request, Tenant $tenant, ?User &$recipient)
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
            'role' => [
                'required',
                'exists:roles,name',
                function ($attribute, $value, $fail) use ($tenant) {
                    // Check if role exists for this tenant
                    $exists = Role::where('name', $value)
                        ->where('team_id', $tenant->id)
                        ->exists();
                    if (! $exists) {
                        $fail('The selected role is invalid for this team.');
                    }
                },
            ],
        ]);

        $existingUser = User::where('email', $request->email)->first();
        $targetRole = $request->input('role');

        if ($existingUser) {
            // Already on the team — the owner included, who isn't on the pivot.
            if (TeamRoster::for($tenant)->isMember($existingUser)) {
                return back()->withInput()->with('error', 'This user is already a member of your team.');
            }

            TeamRoster::for($tenant)->attach($existingUser->id, ['invited_at' => now()]);

            // Auto-assign free plan if user has none
            if (! $existingUser->userPlan) {
                $freePlan = Plan::forProduct()->active()->where('is_default', true)->first()
                    ?? Plan::forProduct()->active()->orderBy('price')->first();
                if ($freePlan) {
                    UserPlan::create(['user_id' => $existingUser->id, 'plan_id' => $freePlan->id]);
                }
            }

            // Assign Tenant Role
            setPermissionsTeamId($tenant->id);
            TeamRoster::for($tenant)->assignRole($existingUser, $targetRole);

            $this->notifyOwnerOfNewMember($tenant, $existingUser, $targetRole);

            $this->logActivity('invited_existing_user_to_website', $existingUser, [
                'tenant_id' => $tenant->id,
                'description' => "Added existing user '{$existingUser->email}' to workspace {$tenant->name} with role {$targetRole}",
            ]);

            $recipient = $existingUser;

            return redirect()->route('tenants.users.index', $tenant)
                ->with('success', __('Welcome aboard! We found an existing account for :name (:email) and they\'ve been added to your workspace\'s team.', ['name' => $existingUser->name, 'email' => $existingUser->email]));
        }

        // New User Logic
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'unique:'.User::class,
        ]);

        $rawPassword = Str::random(10);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($rawPassword),
            'email_verified_at' => now(),
            'must_change_password' => true,
        ]);

        // Auto-assign free plan so invited users skip onboarding
        $freePlan = Plan::forProduct()->active()->where('is_default', true)->first()
            ?? Plan::forProduct()->active()->orderBy('price')->first();
        if ($freePlan) {
            UserPlan::create(['user_id' => $user->id, 'plan_id' => $freePlan->id]);
        }

        TeamRoster::for($tenant)->attach($user->id, ['invited_at' => now()]);

        // Assign Role
        setPermissionsTeamId($tenant->id);
        TeamRoster::for($tenant)->assignRole($user, $targetRole);

        $this->notifyOwnerOfNewMember($tenant, $user, $targetRole);

        $this->logActivity('invited_user_to_website', $user, [
            'tenant_id' => $tenant->id,
            'description' => "Added new user '{$user->name}' to workspace {$tenant->name} with role {$targetRole}",
        ]);

        $recipient = $user;

        return redirect()->route('tenants.users.index', $tenant)
            ->with('success', __('The team invitation was saved and a password setup link has been emailed.'));
    }

    public function edit(Tenant $tenant, User $user)
    {
        $isOwner = $tenant->user_id === Auth::id();
        $isAdmin = Auth::user()->hasRole('Administrator') || Auth::user()->can('admin-access');
        $this->authorizeWorkspace($tenant);

        if (! $isAdmin && ! $isOwner) {
            $this->authorizeTeamAbility($tenant, 'edit');
        }

        $roster = TeamRoster::for($tenant);

        if (! $roster->isMember($user)) {
            abort(404);
        }

        // The owner holds everything by owning the workspace; there's no role
        // to change, and handing them one would only read as a demotion.
        if ($roster->isOwner($user)) {
            return redirect()->route('tenants.users.index', $tenant)
                ->with('error', __("The workspace owner always has full access — there's no role to change."));
        }

        $roles = Role::where('team_id', $tenant->id)->get();
        // Current role for this workspace, read for this team explicitly.
        $currentRole = $roles->firstWhere('name', $roster->roleNamesFor($user)[0] ?? null);
        $roles = $this->offeredRoles($tenant, $roles);
        $roleCards = $this->roleCards($roster, $roles);
        $membership = $roster->pivotRows()[(int) $user->id] ?? null;
        $isPending = $roster->isPending($user);

        return view('tenants.users.edit', compact('tenant', 'user', 'roles', 'currentRole', 'roleCards', 'membership', 'isPending'));
    }

    public function update(Request $request, Tenant $tenant, User $user)
    {
        $isOwner = $tenant->user_id === Auth::id();
        $isAdmin = Auth::user()->hasRole('Administrator') || Auth::user()->can('admin-access');
        $this->authorizeWorkspace($tenant);

        $roster = TeamRoster::for($tenant);

        if (! $roster->isMember($user)) {
            abort(404);
        }

        if (! $isAdmin && ! $isOwner) {
            $this->authorizeTeamAbility($tenant, 'edit');
        }

        if ($roster->isOwner($user)) {
            return redirect()->route('tenants.users.index', $tenant)
                ->with('error', __("The workspace owner always has full access — there's no role to change."));
        }

        // A workspace role grants no authority over the shared sign-in. Older
        // forms may still submit the unchanged email and the "keep" option.
        $credentialError = __('Manage sign-in details through the account profile or password reset.');
        $request->validate([
            'email' => ['sometimes', Rule::in([$user->email])],
            'password_option' => ['sometimes', 'in:keep'],
            'password' => ['prohibited'],
            'role' => [
                'required',
                'exists:roles,name',
                function ($attribute, $value, $fail) use ($tenant) {
                    // The role must belong to THIS workspace's team. Without this a
                    // workspace owner could POST a global role name (e.g. the
                    // team_id=NULL 'Administrator') and syncRoles would grant it —
                    // a privilege escalation to platform admin. Mirrors store().
                    $exists = Role::where('name', $value)
                        ->where('team_id', $tenant->id)
                        ->exists();
                    if (! $exists) {
                        $fail('The selected role is invalid for this team.');
                    }
                },
            ],
        ], [
            'email.in' => $credentialError,
            'password_option.in' => $credentialError,
            'password.prohibited' => $credentialError,
        ]);

        if ($roster->roleNamesFor($user) === [$request->role]) {
            return redirect()->route('tenants.users.index', $tenant)
                ->with('success', __(':name already has the :role role.', ['name' => $user->name, 'role' => $request->role]));
        }

        // The role is resolved for THIS workspace before it is handed over, so a
        // platform role that happens to share the name can never be the one given.
        $targetRole = Role::query()->where('team_id', $tenant->id)->where('guard_name', 'web')
            ->where('name', $request->role)->firstOrFail();
        $this->workspaceMember($tenant, $user)->syncRoles([$targetRole]);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $this->notifyMemberOfRoleChange($tenant, $user, (string) $request->role);

        $this->logActivity('updated_user_role', $user, [
            'tenant_id' => $tenant->id,
            'description' => "Updated role for '{$user->name}' in workspace {$tenant->name} to {$request->role}",
        ]);

        return redirect()->route('tenants.users.index', $tenant)->with('success', 'User role updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tenant $tenant, User $user)
    {
        $isOwner = $tenant->user_id === Auth::id();
        $isAdmin = Auth::user()->hasRole('Administrator') || Auth::user()->can('admin-access');
        $this->authorizeWorkspace($tenant);

        if (! $isAdmin && ! $isOwner) {
            $this->authorizeTeamAbility($tenant, 'delete');
        }

        $roster = TeamRoster::for($tenant);

        if (! $roster->isMember($user)) {
            abort(404);
        }

        if ($roster->isOwner($user)) {
            return back()->with('error', __("The workspace owner can't be removed. Transfer ownership first."));
        }

        if ((int) $user->id === (int) Auth::id()) {
            return back()->with('error', __("You can't remove yourself here. Ask the owner or another team admin."));
        }

        $this->removeMembership($tenant, $user);

        $this->logActivity('removed_user_from_website', $user, [
            'tenant_id' => $tenant->id,
            'description' => "Removed user '{$user->name}' from workspace {$tenant->name}",
        ]);

        return redirect()->route('tenants.users.index', $tenant)->with('success', __(':name has been removed from the team.', ['name' => $user->name]));
    }

    public function resendInvite(Tenant $tenant, User $user)
    {
        $this->authorizeWorkspace($tenant);

        if (! Auth::user()->can('admin-access') && $tenant->user_id !== Auth::id()) {
            $this->authorizeTeamAbility($tenant, 'create');
        }

        $roster = TeamRoster::for($tenant);

        if (! $roster->isMember($user) || $roster->isOwner($user)) {
            abort(404);
        }

        if (! $roster->isPending($user)) {
            return back()->with('error', __(':name has already joined — there is no invite to resend.', ['name' => $user->name]));
        }

        if (! InvitationDelivery::send($user, $tenant)) {
            return back()->with('error', __('The invitation email could not be sent. Please try Resend invite again.'));
        }

        // Update invited_at
        $roster->pivot()->where('user_id', $user->id)->update(['invited_at' => now()]);

        return back()->with('success', __('Invite resent to :email.', ['email' => $user->email]));
    }

    /**
     * A member leaves the workspace themselves.
     *
     * Asks for the password and the word LEAVE, so it cannot happen by a stray
     * click. The owner cannot leave: ownership is transferred first.
     */
    public function leave(Request $request, Tenant $tenant)
    {
        $request->validateWithBag('workspaceLeave', [
            'password' => ['required', 'current_password'],
            'confirmation' => ['required', 'string', 'in:LEAVE'],
        ]);

        $user = $request->user();
        $roster = TeamRoster::for($tenant);

        if ($roster->isOwner($user)) {
            return back()->withErrors([
                'workspace' => __('Transfer workspace ownership before leaving this workspace.'),
            ], 'workspaceLeave');
        }

        if (! $roster->isMember($user)) {
            abort(404);
        }

        $this->removeMembership($tenant, $user);

        if ((int) $request->session()->get('active_tenant_id') === (int) $tenant->id) {
            $request->session()->forget('active_tenant_id');
        }

        $this->logActivity('left_workspace', $tenant, [
            'tenant_id' => $tenant->id,
            'description' => "Left workspace '{$tenant->name}'",
        ]);

        return redirect()->route('dashboard')->with('success', __('You have left this workspace.'));
    }

    /**
     * The acting user must belong to this workspace (owner or member), or be a
     * platform administrator. Asks the app's workspace policy when it has one
     * with an access() rule, and the team roster otherwise.
     */
    protected function authorizeWorkspace(Tenant $tenant): void
    {
        $policy = Gate::getPolicyFor($tenant);

        if ($policy && method_exists($policy, 'access')) {
            $this->authorize('access', $tenant);

            return;
        }

        $user = Auth::user();
        abort_unless(TeamRoster::for($tenant)->isMember($user) || $user->can('admin-access'), 403);
    }

    /**
     * The acting member needs the team permission for this action in THIS
     * workspace. Roles are re-read for the workspace first: a relation loaded
     * earlier in the request may belong to another workspace or to none.
     */
    protected function authorizeTeamAbility(Tenant $tenant, string $action): void
    {
        setPermissionsTeamId($tenant->id);
        Auth::user()->unsetRelation('roles')->unsetRelation('permissions');

        $this->authorize(TeamRoster::ability('members', $action));
    }

    /**
     * Take a member off the workspace: the membership row, their roles in this
     * workspace, and any permission granted to them directly in it.
     */
    protected function removeMembership(Tenant $tenant, User $user): void
    {
        TeamRoster::for($tenant)->pivot()->where('user_id', $user->id)->delete();

        $member = $this->workspaceMember($tenant, $user);
        $member->syncRoles([]);
        $member->permissions()->detach();

        // Whatever the relation left behind goes too: no role in this
        // workspace may outlive the membership.
        $roleModel = config('permission.models.role');
        DB::connection((new $roleModel)->getConnectionName())
            ->table(config('permission.table_names.model_has_roles', 'model_has_roles'))
            ->where(config('permission.column_names.team_foreign_key', 'team_id'), $tenant->id)
            ->where('model_type', $user->getMorphClass())
            ->where(config('permission.column_names.model_morph_key', 'model_id'), $user->id)
            ->delete();

        $user->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The member as this workspace's database sees them, for writing roles and
     * permissions. Role rows live with the workspace, the account lives in the
     * shared database; writing through the workspace's connection keeps every
     * role write on one connection, as TeamRoster::assignRole() does. The
     * account itself is never saved through this copy.
     */
    protected function workspaceMember(Tenant $tenant, User $user): User
    {
        setPermissionsTeamId($tenant->id);

        $member = clone $user;
        $member->setConnection($tenant->getConnectionName());
        $member->unsetRelation('roles')->unsetRelation('permissions');

        return $member;
    }

    /**
     * Tell the workspace owner that someone was added to the team. A failed
     * notification never undoes the invitation.
     */
    protected function notifyOwnerOfNewMember(Tenant $tenant, User $member, string $role): void
    {
        rescue(fn () => optional($tenant->user)->notify(new NewMemberJoinedWorkspace([
            'member' => $member->name ?? $member->email,
            'email' => $member->email,
            'workspace' => $tenant->name,
            'role' => $role,
        ])), null, false);
    }

    /**
     * Tell a member that their role in the workspace changed.
     */
    protected function notifyMemberOfRoleChange(Tenant $tenant, User $member, string $role): void
    {
        rescue(fn () => $member->notify(new MemberPermissionChanged([
            'workspace' => $tenant->name,
            'role' => $role,
        ])), null, false);
    }

    /**
     * The workspace's roles as the role picker shows them.
     *
     * @param  iterable<int, Role>|null  $roles  limit the cards to these roles
     * @return list<array<string, mixed>>
     */
    protected function roleCards(TeamRoster $roster, $roles = null): array
    {
        $assignable = $this->assignablePermissionNames($roster->tenant());
        $offered = $roles === null ? null : collect($roles)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $roster->roles()
            ->when($offered !== null, fn ($all) => $all->filter(fn ($role) => in_array((int) $role->id, $offered, true)))
            ->map(fn ($role) => $this->roleCard($roster, $role, $assignable))
            ->values()
            ->all();
    }

    /**
     * The permission names a workspace role could hold: what a role card's
     * "full access" is counted against. Matches what the role editor offers.
     *
     * @return list<string>
     */
    protected function assignablePermissionNames(Tenant $tenant): array
    {
        return app(TenantRoleController::class)->assignablePermissions($tenant)->pluck('name')->values()->all();
    }

    /**
     * One role as the role picker shows it.
     *
     * @param  list<string>  $assignable
     * @return array<string, mixed>
     */
    protected function roleCard(TeamRoster $roster, $role, array $assignable): array
    {
        return $roster->summarise($role, $assignable);
    }

    /**
     * The roles the acting user may hand out: a member sees only roles that
     * allow nothing beyond their own. The role-delegation middleware enforces
     * the same rule on save; this keeps the picker honest.
     *
     * @param  Collection<int, Role>  $roles
     * @return Collection<int, Role>
     */
    protected function offeredRoles(Tenant $tenant, $roles)
    {
        return app(EnsureWorkspaceRoleDelegation::class)->rolesActorMayHandOut(Auth::user(), $tenant, collect($roles));
    }
}
