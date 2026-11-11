<?php

namespace Ssuite\Core\Support\Team;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewAccountCreated;
use App\Notifications\WorkspaceInvited;
use Illuminate\Support\Facades\Log;

/** Delivery is retryable; a transport failure must not undo a saved invitation. */
class InvitationDelivery
{
    public static function send(User $user, Tenant $tenant): bool
    {
        try {
            $user->notifyNow($user->must_change_password
                ? new NewAccountCreated
                : new WorkspaceInvited($tenant));

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Workspace invitation email could not be sent.', [
                'tenant_id' => $tenant->getKey(),
                'user_id' => $user->getKey(),
                'exception_type' => $exception::class,
            ]);

            return false;
        }
    }
}
