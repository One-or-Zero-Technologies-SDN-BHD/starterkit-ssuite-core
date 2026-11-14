<?php

namespace Ssuite\Core\Support\Auth;

use Illuminate\Http\Request;

/** A completed challenge belongs to one identity, not merely one browser. */
class TwoFactorSession
{
    public static function verified(Request $request): bool
    {
        return $request->user() !== null
            && $request->session()->get('two_factor_verified') === true
            && (string) $request->session()->get('two_factor_verified_user_id') === (string) $request->user()->getAuthIdentifier();
    }

    public static function verify(Request $request): void
    {
        $request->session()->put([
            'two_factor_verified' => true,
            'two_factor_verified_user_id' => (string) $request->user()->getAuthIdentifier(),
        ]);
    }

    public static function clear(Request $request): void
    {
        $request->session()->forget(['two_factor_verified', 'two_factor_verified_user_id', 'profile.two_factor_setup']);
    }
}
