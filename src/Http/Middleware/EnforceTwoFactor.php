<?php

namespace Ssuite\Core\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor enforcement layer (canonical, suite-wide). Two behaviours:
 *
 *  1. Session challenge — a user who HAS 2FA enabled must pass the per-session
 *     /two-factor-challenge (TOTP or recovery code) before reaching the app.
 *     Until session('two_factor_verified') is true, they are redirected there.
 *
 *  2. Enrollment nudge — on plans that require 2FA (Pro+ tiers), a user who has
 *     NOT enrolled is soft-redirected to their profile to set it up.
 *
 * Auth/setup routes are bypassed so users can actually log in, verify, and
 * enroll. Registered in the web group after SetTenantPermissions.
 */
class EnforceTwoFactor
{
    private const ENFORCED_TIER_KEYWORDS = ['pro', 'studio', 'agency', 'business', 'custom'];

    /**
     * Routes an ENROLLED-but-unverified session may reach: strictly the
     * challenge itself plus sign-out and account-recovery flows.
     *
     * SECURITY: this list must stay minimal. It previously bypassed the whole
     * `two-factor.` prefix and `profile.` — which let a session that had NOT
     * passed the challenge regenerate recovery codes (no password required),
     * read them on the profile page, and use one to pass the challenge:
     * a complete 2FA bypass with a stolen session cookie.
     */
    private const CHALLENGE_BYPASS_ROUTES = [
        'logout', 'two-factor.challenge', 'two-factor.challenge.store',
        'verification.*', 'sso.*',
        // The guest reset flow only — never `password.*`. That wildcard also
        // matched `password.update`, which skips the current-password check
        // while must_change_password is set (every invited member, and users
        // flagged by RemediatePlaintextCredentials): a stolen pre-challenge
        // cookie could set a new password and take the account. 2026-10-23.
        'password.request', 'password.email', 'password.reset', 'password.store',
    ];

    /**
     * Routes a NOT-enrolled user (whose plan requires 2FA) may reach — they
     * need the profile + two-factor endpoints to actually enrol, and this
     * wider list is what prevents the nudge from redirect-looping.
     */
    private const NUDGE_BYPASS_ROUTES = [
        'profile.*', 'logout', 'two-factor.*', 'verification.*', 'password.*', 'sso.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // 1) Enrolled but not yet verified this session → challenge.
        if ($this->hasTwoFactorEnabled($user)) {
            if ($request->session()->get('two_factor_verified') === true
                || $request->routeIs(...self::CHALLENGE_BYPASS_ROUTES)) {
                return $next($request);
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => __('Two-factor verification required.')], 423);
            }

            return redirect()->guest(route('two-factor.challenge'));
        }

        // 2) Plan requires 2FA but the user hasn't enrolled → soft nudge.
        if ($this->planRequiresTwoFactor($user) && ! $request->routeIs(...self::NUDGE_BYPASS_ROUTES)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('Two-factor authentication is required for your plan.')], 423);
            }

            // Straight to the Security tab, where the 2FA form is (the bare
            // profile URL opened on "Profile" with no visible reason).
            return redirect()->to(route('profile.edit').'#security')
                ->with('warning', __('Your plan requires two-factor authentication. Please enable it now to keep your account secure.'));
        }

        return $next($request);
    }

    private function hasTwoFactorEnabled(User $user): bool
    {
        if (! Schema::connection('central')->hasColumn('users', 'two_factor_confirmed_at')) {
            return false;
        }

        return method_exists($user, 'hasEnabledTwoFactor')
            ? $user->hasEnabledTwoFactor()
            : ! is_null($user->two_factor_confirmed_at);
    }

    private function planRequiresTwoFactor(User $user): bool
    {
        try {
            $planName = strtolower((string) ($user->userPlan?->plan?->name ?? ''));
        } catch (\Throwable $e) {
            return false;
        }

        if ($planName === '') {
            return false;
        }

        foreach (self::ENFORCED_TIER_KEYWORDS as $keyword) {
            if (str_contains($planName, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
