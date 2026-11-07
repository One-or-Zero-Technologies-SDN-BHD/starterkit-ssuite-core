<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\FxRate;
use App\Models\SystemSetting;

/**
 * The app's billing currency, chosen in System Settings → Billing.
 *
 * One currency for the whole app: every customer sees and pays in it. MYR is
 * the default and every plan's base price; a plan is sold in USD only once a
 * USD price is entered for it. Records already charged (payments, invoices,
 * subscriptions) keep the currency they were charged in, so switching the
 * setting never relabels an existing amount.
 */
class Currency
{
    public const MYR = 'MYR';

    public const USD = 'USD';

    public const DEFAULT = self::MYR;

    /** System setting holding the code. */
    public const SETTING = 'default_currency';

    /** Supported codes and the symbol shown in front of amounts. */
    public const SYMBOLS = [self::MYR => 'RM', self::USD => 'US$'];

    /** Gateways that can charge each currency. CHIP's API accepts MYR only. */
    private const GATEWAY_CURRENCIES = [
        'chip' => [self::MYR],
        'stripe' => [self::MYR, self::USD],
    ];

    /** The current billing currency (MYR unless USD is chosen). */
    public static function code(): string
    {
        $saved = rescue(fn () => SystemSetting::get(self::SETTING), null, false);

        return self::normalize($saved);
    }

    /** A supported code for any input; anything unknown or empty is MYR. */
    public static function normalize(mixed $code): string
    {
        $code = strtoupper(trim((string) $code));

        return array_key_exists($code, self::SYMBOLS) ? $code : self::DEFAULT;
    }

    public static function isUsd(?string $code = null): bool
    {
        return self::normalize($code ?? self::code()) === self::USD;
    }

    /** "RM" or "US$". */
    public static function symbol(?string $code = null): string
    {
        return self::SYMBOLS[self::normalize($code ?? self::code())];
    }

    /** "RM 1,250.00" / "US$ 1,250.00". */
    public static function format(float|int|string|null $amount, ?string $code = null, int $decimals = 2): string
    {
        return self::symbol($code).' '.number_format((float) $amount, $decimals);
    }

    /** @return array<string, string> code => "MYR (RM)" */
    public static function options(): array
    {
        $options = [];
        foreach (self::SYMBOLS as $code => $symbol) {
            $options[$code] = $code.' ('.$symbol.')';
        }

        return $options;
    }

    /** Whether $gateway can charge $code (the current currency by default). */
    public static function supportedBy(?string $gateway, ?string $code = null): bool
    {
        $allowed = self::GATEWAY_CURRENCIES[strtolower((string) $gateway)] ?? [self::MYR];

        return in_array(self::normalize($code ?? self::code()), $allowed, true);
    }

    /** Shown when the configured gateway cannot charge the billing currency. */
    public static function unsupportedMessage(?string $code = null): string
    {
        return __('Online payment in :currency needs Stripe. Please contact support.', ['currency' => self::normalize($code ?? self::code())]);
    }

    /**
     * $amount in $from expressed in $to, using the latest Bank Negara rates.
     * Null when a needed rate has never been fetched.
     */
    public static function convert(float $amount, string $from, string $to): ?float
    {
        $from = self::normalize($from);
        $to = self::normalize($to);
        if ($from === $to) {
            return $amount;
        }

        $fromRate = rescue(fn () => FxRate::rateToMyr($from), null, false);
        $toRate = rescue(fn () => FxRate::rateToMyr($to), null, false);

        if (! $fromRate || ! $toRate) {
            return null;
        }

        return round($amount * $fromRate / $toRate, 2);
    }
}
