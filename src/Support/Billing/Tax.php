<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\SystemSetting;

/**
 * Malaysian SST on subscription payments, switched on or off in System
 * Settings → Billing. Plan prices are entered and shown EXCLUDING tax; when SST
 * is on, checkout and automatic renewal add it on top of the amount after any
 * coupon. Everything priced or credited (subscription amount, locked prices,
 * upgrade credit, affiliate commission) stays pre-tax.
 */
class Tax
{
    public const DEFAULT_RATE = 8.0;

    public static function enabled(): bool
    {
        return (string) self::setting('billing_sst_enabled', '0') === '1';
    }

    /** The configured rate in percent (0–100, two decimals). */
    public static function rate(): float
    {
        $rate = self::setting('billing_sst_rate');

        return is_numeric($rate) ? max(0.0, min(100.0, round((float) $rate, 2))) : self::DEFAULT_RATE;
    }

    public static function registrationNo(): ?string
    {
        $number = trim((string) self::setting('billing_sst_no', ''));

        return $number === '' ? null : $number;
    }

    /** "8" for 8.00, "6.5" for 6.50. */
    public static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }

    /** "SST (8%)". */
    public static function label(?float $rate = null): string
    {
        return __('SST (:rate%)', ['rate' => self::formatRate($rate ?? self::rate())]);
    }

    /**
     * Add SST to a pre-tax subtotal (the price after any coupon).
     *
     * @return array{subtotal: float, tax: float, rate: ?float, total: float}
     */
    public static function apply(float $subtotal): array
    {
        $subtotal = round(max(0.0, $subtotal), 2);

        if ($subtotal <= 0 || ! self::enabled()) {
            return ['subtotal' => $subtotal, 'tax' => 0.0, 'rate' => null, 'total' => $subtotal];
        }

        $rate = self::rate();
        $tax = round($subtotal * $rate / 100, 2);

        return ['subtotal' => $subtotal, 'tax' => $tax, 'rate' => $rate, 'total' => round($subtotal + $tax, 2)];
    }

    /**
     * The payment_transactions columns for an applied charge.
     *
     * @param  array{subtotal: float, tax: float, rate: ?float, total: float}  $charge
     * @return array{amount: float, subtotal_amount: float, tax_amount: float, tax_rate: ?float}
     */
    public static function columns(array $charge): array
    {
        return [
            'amount' => $charge['total'],
            'subtotal_amount' => $charge['subtotal'],
            'tax_amount' => $charge['tax'],
            'tax_rate' => $charge['rate'],
        ];
    }

    private static function setting(string $key, mixed $default = null): mixed
    {
        return rescue(fn () => SystemSetting::get($key, $default), $default, false);
    }
}
