<?php

namespace Ssuite\Core\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Observers\RecordsModelChanges;
use App\Support\Profile\TwoFactorSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Ssuite\Core\Support\Auth\TwoFactorSession;

/**
 * TOTP two-factor authentication management (enrollment side). Lives on the
 * central User. Secret + recovery codes are encrypted via the model casts.
 *
 * Flow: enable() generates a pending secret + recovery codes (shows the QR on
 * the profile) → confirm() verifies a TOTP code and stamps two_factor_confirmed_at
 * → disable() clears everything (password-confirmed) → regenerateRecoveryCodes().
 */
class TwoFactorController extends Controller
{
    public function __construct(private Google2FA $google2fa) {}

    public function enable(Request $request): RedirectResponse
    {
        $request->validateWithBag('twoFactorAction', ['password' => ['required', 'current_password']]);
        $user = $request->user();

        if ($user->hasEnabledTwoFactor()) {
            return back()->with('status', 'two-factor-already-enabled');
        }

        if (! $user->twoFactorPending()) {
            RecordsModelChanges::withoutRecording(fn () => $user->forceFill([
                'two_factor_secret' => $this->google2fa->generateSecretKey(),
                'two_factor_recovery_codes' => $this->generateRecoveryCodes(),
                'two_factor_confirmed_at' => null,
            ])->save());
        }
        TwoFactorSetup::allow($request, $user);

        return redirect()->route('profile.edit')->withFragment('security')->with('status', 'two-factor-pending');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->twoFactorPending() || ! TwoFactorSetup::allowed($user)) {
            return back()->withErrors(['code' => __('Start two-factor setup first.')]);
        }

        $code = preg_replace('/\s+/', '', (string) $request->input('code'));
        if (! $this->google2fa->verifyKey((string) $user->two_factor_secret, $code)) {
            return back()->withErrors(['code' => __('That code is invalid. Check your authenticator app and try again.')]);
        }

        RecordsModelChanges::withoutRecording(fn () => $user->forceFill(['two_factor_confirmed_at' => now()])->save());
        ActivityLog::record('security.two_factor_enabled', $user, [], $user);
        // This session is already proven — don't immediately challenge them.
        TwoFactorSession::verify($request);

        $request->session()->forget('profile.two_factor_setup');

        return redirect()->route('profile.edit')->withFragment('security')->with('status', 'two-factor-confirmed');
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validateWithBag('twoFactorAction', ['password' => ['required', 'current_password']]);

        RecordsModelChanges::withoutRecording(fn () => $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save());
        ActivityLog::record('security.two_factor_disabled', $request->user(), [], $request->user());

        TwoFactorSession::clear($request);

        return back()->with('status', 'two-factor-disabled');
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        // Same bar as disable(): recovery codes are a 2FA bypass token, so
        // minting new ones must prove the current password. Without this, a
        // hijacked session could regenerate codes and use one at the challenge.
        $request->validateWithBag('twoFactorAction', ['password' => ['required', 'current_password']]);

        $user = $request->user();

        if (! $user->hasEnabledTwoFactor()) {
            return back();
        }

        RecordsModelChanges::withoutRecording(fn () => $user->forceFill(['two_factor_recovery_codes' => $this->generateRecoveryCodes()])->save());
        ActivityLog::record('security.recovery_codes_regenerated', $user, [], $user);

        return $this->codesResponse($request, $user);
    }

    public function revealRecoveryCodes(Request $request)
    {
        $request->validateWithBag('twoFactorAction', ['password' => ['required', 'current_password']]);
        abort_unless($request->user()->hasEnabledTwoFactor(), 409);

        return $this->codesResponse($request, $request->user());
    }

    private function codesResponse(Request $request, User $user)
    {
        $codes = $user->recoveryCodes();
        $response = $request->expectsJson() ? response()->json(['codes' => $codes])
            : response()->view('profile.recovery-codes', compact('codes'));

        return $response->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache');
    }

    /** Eight single-use recovery codes (XXXXX-XXXXX). */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)))
            ->all();
    }
}
