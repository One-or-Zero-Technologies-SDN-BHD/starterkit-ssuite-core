<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\SystemSetting;

/**
 * The platform switch for taking payments online (System Settings → Billing →
 * Payment Gateway). On by default. When off, no checkout, add-on purchase,
 * card saving or automatic renewal reaches the gateway; customers who want a
 * paid plan are asked to contact support. Payments already in flight still
 * settle through the gateway callback and return URL.
 */
class OnlinePayments
{
    public const SETTING = 'payment_gateway_enabled';

    public static function enabled(): bool
    {
        return (string) SystemSetting::get(self::SETTING, '1') !== '0';
    }

    /**
     * Who customers should contact: the billing email, else the billing phone,
     * else the general support email.
     *
     * @return array{label: ?string, url: ?string}
     */
    public static function supportContact(): array
    {
        if ($email = self::clean(SystemSetting::get('billing_email'))) {
            return ['label' => $email, 'url' => 'mailto:'.$email];
        }

        if ($phone = self::clean(SystemSetting::get('billing_phone'))) {
            return ['label' => $phone, 'url' => 'tel:'.preg_replace('/[^0-9+]/', '', $phone)];
        }

        if ($email = self::clean(SystemSetting::get('support_email'))) {
            return ['label' => $email, 'url' => 'mailto:'.$email];
        }

        return ['label' => null, 'url' => null];
    }

    /** The message shown when a paid action is refused. */
    public static function unavailableMessage(): string
    {
        $contact = self::supportContact()['label'];

        return $contact
            ? __('Online payment is currently unavailable. To upgrade, please contact support at :contact.', ['contact' => $contact])
            : __('Online payment is currently unavailable. To upgrade, please contact our administrator or support team.');
    }

    private static function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
