<?php

namespace Ssuite\Core\Support;

use App\Support\SessionFingerprint;
use Illuminate\Http\Request;

class AuthenticationSession
{
    /** A new login must not inherit proofs or workspace context from an old identity. */
    public static function reset(Request $request): void
    {
        $request->session()->forget([
            'two_factor_verified', 'two_factor_verified_user_id', 'profile.two_factor_setup',
            'active_tenant_id', 'workspace_master_view', 'workspace_intended', SessionFingerprint::SESSION_KEY,
            'auth.password_confirmed_at', 'sso.via',
        ]);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
    }
}
