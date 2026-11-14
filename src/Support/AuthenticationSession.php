<?php

namespace Ssuite\Core\Support;

use App\Support\SessionFingerprint;
use Illuminate\Http\Request;
use Ssuite\Core\Support\Auth\TwoFactorSession;

class AuthenticationSession
{
    /** A new login must not inherit proofs or workspace context from an old identity. */
    public static function reset(Request $request): void
    {
        TwoFactorSession::clear($request);
        $request->session()->forget([
            'active_tenant_id', 'workspace_master_view', 'workspace_intended', SessionFingerprint::SESSION_KEY,
            'auth.password_confirmed_at', 'sso.via',
        ]);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
    }
}
