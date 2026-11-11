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
use App\Services\WorkspaceSeatGuard;
use App\Support\Team\TeamRoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
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
        $this->authorize('access', $tenant);

        if (! $isAdmin && ! $isOwner) {
            setPermissionsTeamId($tenant->id);
            $this->authorize('team-user-list');
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
        $this->authorize('access', $tenant);

        if (! $isAdmin && ! $isOwner) {
            setPermissionsTeamId($tenant->id);
            $this->authorize('team-user-create');
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
        $this->authorize('access', $tenant);

        if (! $isAdmin && ! $isOwner) {
            setPermissionsTeamId($tenant->id);
            $this->authorize('team-user-create');
        }

        $roles = Role::where('team_id', $tenant->id)->get();

        if ($roles->isEmpty()) {
            return redirect()->route('tenants.roles.index', $tenant)
                ->with('error', 'Please create at least one role (e.g. Manager, Editor) before inviting members to your team.');
        }

        $roster = TeamRoster::for($tenant);
        $seats = $roster->seats();
        $roleCards = $this->roleCards($roster);

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
                $this->authorize('access', $tenant);

                if (! $isAdmin && ! $isOwner) {
                    setPermissionsTeamId($tenant->id);
                    $this->authorize('team-user-create');
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

    private function storeWithinCapacity(Request $request, Tenant $tenant, ?User &$recipient)
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
        $this->authorize('access', $tenant);

        if (! $isAdmin && ! $isOwner) {
            setPermissionsTeamId($tenant->id);
            $this->authorize('team-user-edit');
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
        $roleCards = $this->roleCards($roster);
        $membership = $roster->pivotRows()[(int) $user->id] ?? null;
        $isPending = $roster->isPending($user);

        return view('tenants.users.edit', compact('tenant', 'user', 'roles', 'currentRole', 'roleCards', 'membership', 'isPending'));
    }

    public function update(Request $request, Tenant $tenant, User $user)
    {
        $isOwner = $tenant->user_id === Auth::id();
        $isAdmin = Auth::user()->hasRole('Administrator') || Auth::user()->can('admin-access');
        $this->authorize('access', $tenant);

        $roster = TeamRoster::for($tenant);

        if (! $roster->isMember($user)) {
            abort(404);
        }

        if (! $isAdmin && ! $isOwner) {
            setPermissionsTeamId($tenant->id);
            $this->authorize('team-user-edit');
        }

        if ($roster->isOwner($user)) {
            return redirect()->route('tenants.users.index', $tenant)
                ->with('error', __("The workspace owner always has full access — there's no role to change."));
        }

        $request->validate([
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
        ]);

        if ($roster->roleNamesFor($user) === [$request->role]) {
            return redirect()->route('tenants.users.index', $tenant)
                ->with('success', __(':name already has the :role role.', ['name' => $user->name, 'role' => $request->role]));
        }

        // Sync role for this team
        setPermissionsTeamId($tenant->id);

        $user->syncRoles([$request->role]);

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
        $this->authorize('access', $tenant);

        if (! $isAdmin && ! $isOwner) {
            setPermissionsTeamId($tenant->id);
            $this->authorize('team-user-delete');
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

        $roster->pivot()->where('user_id', $user->id)->delete();

        // Also remove roles for this team
        setPermissionsTeamId($tenant->id);
        $user->syncRoles([]);

        $this->logActivity('removed_user_from_website', $user, [
            'tenant_id' => $tenant->id,
            'description' => "Removed user '{$user->name}' from workspace {$tenant->name}",
        ]);

        return redirect()->route('tenants.users.index', $tenant)->with('success', __(':name has been removed from the team.', ['name' => $user->name]));
    }

    public function resendInvite(Tenant $tenant, User $user)
    {
        $this->authorize('access', $tenant);

        if (! Auth::user()->can('admin-access') && $tenant->user_id !== Auth::id()) {
            setPermissionsTeamId($tenant->id);
            $this->authorize('team-user-create');
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
     * The workspace's roles as the role picker shows them.
     *
     * @return list<array<string, mixed>>
     */
    private function roleCards(TeamRoster $roster): array
    {
        $offered = app(TenantRoleController::class)->assignablePermissions($roster->tenant())->pluck('name')->all();

        return $roster->roles()->map(fn ($role) => $roster->summarise($role, $offered))->values()->all();
    }
}
