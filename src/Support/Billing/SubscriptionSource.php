<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\Subscription;
use App\Services\AdminPlanOverride;
use Illuminate\Contracts\Encryption\DecryptException;
use JsonException;
use UnexpectedValueException;

/** Read-only source labels; unreadable metadata never becomes an empty payment record. */
class SubscriptionSource
{
    /** Null explicitly means the original payment details are unavailable. */
    public static function label(Subscription $subscription): ?string
    {
        try {
            $details = $subscription->payment_details ?? [];
        } catch (DecryptException|JsonException|UnexpectedValueException) {
            return null;
        }

        return match ($details['source'] ?? null) {
            AdminPlanOverride::SOURCE_COMP => __('Admin comp'),
            AdminPlanOverride::SOURCE_MANUAL => __('Manual payment'),
            default => ucfirst($subscription->payment_gateway ?? 'self-serve'),
        };
    }
}
