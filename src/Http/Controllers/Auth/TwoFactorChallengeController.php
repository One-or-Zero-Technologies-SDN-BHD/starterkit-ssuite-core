<?php

namespace Ssuite\Core\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use PragmaRX\Google2FA\Google2FA;
use Ssuite\Core\Support\Auth\RecoveryCodes;
use Ssuite\Core\Support\Auth\TwoFactorSession;

/**
 * Per-session 2FA challenge. After login, a user with 2FA enabled must pass this
 * once per session (TOTP code or a single-use recovery code) before the
 * EnforceTwoFactor middleware lets them into the app. Success sets the
 * identity-bound session proof.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(private Google2FA $google2fa) {}

    public function show(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->hasEnabledTwoFactor() || TwoFactorSession::verified($request)) {
            return redirect()->intended(route('dashboard'));
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->hasEnabledTwoFactor(), 403);

        $request->validate(['auth' => ['required', 'string']]);
        $input = preg_replace('/\s+/', '', (string) $request->input('auth'));

        // 1) TOTP code from the authenticator app.
        if ($this->google2fa->verifyKey((string) $user->two_factor_secret, $input)) {
            TwoFactorSession::verify($request);

            return redirect()->intended(route('dashboard'));
        }

        // 2) Single-use recovery code (case-insensitive).
        if (RecoveryCodes::consume($user, $input)) {
            TwoFactorSession::verify($request);

            return redirect()->intended(route('dashboard'))
                ->with('warning', __('You signed in with a recovery code. Regenerate your codes from your profile.'));
        }

        return back()->withErrors(['auth' => __('Invalid authentication code.')]);
    }
}
