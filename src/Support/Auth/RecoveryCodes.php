<?php

namespace Ssuite\Core\Support\Auth;

use App\Models\User;
use RuntimeException;

final class RecoveryCodes
{
    /** Claim against the current central row, never a request's cached code list. */
    public static function consume(User $user, string $input): bool
    {
        $input = strtoupper(preg_replace('/\s+/', '', $input) ?? '');
        if ($input === '' || ! $user->exists) {
            return false;
        }

        $stored = $user->getConnection()->transaction(function () use ($user, $input): ?string {
            $current = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();
            if (! $current || ! $current->hasEnabledTwoFactor()) {
                return null;
            }

            $codes = $current->recoveryCodes();
            $matches = static fn ($code): bool => is_string($code) && hash_equals(strtoupper($code), $input);
            $remaining = array_values(array_filter($codes, static fn ($code): bool => ! $matches($code)));
            if (count($remaining) === count($codes)) {
                return null;
            }
            if (! $current->forceFill(['two_factor_recovery_codes' => $remaining])->save()) {
                throw new RuntimeException('The recovery code could not be consumed.');
            }

            return $current->getRawOriginal('two_factor_recovery_codes');
        });

        if ($stored === null) {
            return false;
        }

        $attributes = $user->getAttributes();
        $attributes['two_factor_recovery_codes'] = $stored;
        $user->setRawAttributes($attributes)->syncOriginalAttribute('two_factor_recovery_codes');

        return true;
    }
}
