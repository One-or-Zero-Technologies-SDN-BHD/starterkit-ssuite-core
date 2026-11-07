<?php

namespace Ssuite\Core\Services\Payment;

use App\Models\SystemSetting;
use App\Services\Payment\Gateways\ChipGateway;
use App\Services\Payment\Gateways\StripeGateway;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\SupportsRecurringPayments;
use Illuminate\Http\Request;

/**
 * The suite takes payments through two gateways only: CHIP (default; FPX +
 * cards) and Stripe (cards). Both can save a card for automatic renewal.
 */
class PaymentGatewayFactory
{
    public const DEFAULT = 'chip';

    /**
     * Supported payment gateways
     */
    protected static array $gateways = [
        'chip' => ChipGateway::class,
        'stripe' => StripeGateway::class,
    ];

    /** Runtime/test overrides: gateway name → concrete instance (see fake()). */
    protected static array $overrides = [];

    /**
     * Bind a concrete gateway instance for a name (tests + custom wiring).
     * make() returns it verbatim, bypassing SystemSetting config hydration.
     */
    public static function fake(string $name, PaymentGatewayInterface $instance): void
    {
        static::$overrides[strtolower($name)] = $instance;
    }

    /** Clear runtime overrides (tests). */
    public static function clearFakes(): void
    {
        static::$overrides = [];
    }

    /**
     * The gateway the admin selected. A stored name that is no longer supported
     * (ToyyibPay, Billplz, Bayarcash, FIU and SecurePay were removed) falls back
     * to CHIP, so checkout keeps working until the admin re-saves the setting.
     */
    public static function configured(): string
    {
        $name = strtolower((string) SystemSetting::get('payment_gateway', static::DEFAULT));

        return isset(static::$gateways[$name]) || isset(static::$overrides[$name]) ? $name : static::DEFAULT;
    }

    /**
     * Is the named (or configured) gateway missing the credentials it needs to
     * take a payment? Checkout and card-saving show "being set up" instead of
     * failing at the gateway, and admins see a warning.
     */
    public static function credentialsMissing(?string $name = null): bool
    {
        $name = strtolower($name ?? static::configured());

        if (isset(static::$overrides[$name])) {
            return false;
        }

        $required = match ($name) {
            'chip' => ['chip_secret_key', 'chip_brand_id'],
            'stripe' => ['stripe_secret'],
            default => [],
        };

        foreach ($required as $key) {
            if (trim((string) SystemSetting::get($key, '')) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Which gateway sent an inbound callback: Stripe signs its webhooks with a
     * Stripe-Signature header; everything else is a CHIP purchase callback.
     */
    public static function forCallback(Request $request): string
    {
        return $request->hasHeader('Stripe-Signature') ? 'stripe' : 'chip';
    }

    /** Can the named (or currently configured) gateway store a card and charge it again? */
    public static function supportsRecurring(?string $name = null): bool
    {
        try {
            return static::make($name) instanceof SupportsRecurringPayments;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Create a payment gateway instance
     *
     * @throws \Exception
     */
    public static function make(?string $gatewayName = null, bool $useSystemSettings = true): PaymentGatewayInterface
    {
        if ($useSystemSettings && ! $gatewayName) {
            $gatewayName = static::configured();
        }

        $gatewayName = strtolower($gatewayName ?? config('services.payment.default', static::DEFAULT));

        if (isset(static::$overrides[$gatewayName])) {
            return static::$overrides[$gatewayName];
        }

        if (! isset(static::$gateways[$gatewayName])) {
            throw new \Exception("Payment gateway [{$gatewayName}] not supported. Available: ".implode(', ', array_keys(static::$gateways)));
        }

        // Dynamically override config with SystemSettings
        if ($useSystemSettings) {
            if ($gatewayName === 'stripe') {
                config(['services.stripe.key' => SystemSetting::get('stripe_key')]);
                config(['services.stripe.secret' => SystemSetting::get('stripe_secret')]);
                config(['services.stripe.webhook_secret' => SystemSetting::get('stripe_webhook_secret')]);
            } elseif ($gatewayName === 'chip') {
                config(['services.chip.secret_key' => SystemSetting::get('chip_secret_key')]);
                config(['services.chip.brand_id' => SystemSetting::get('chip_brand_id')]);
                config(['services.chip.public_key' => SystemSetting::get('chip_public_key') ?: config('services.chip.public_key')]);
            }
        }

        return new static::$gateways[$gatewayName];
    }

    /**
     * Get all supported gateways
     */
    public static function getSupportedGateways(): array
    {
        return array_keys(static::$gateways);
    }

    /**
     * Check if a gateway is supported
     */
    public static function isSupported(string $gatewayName): bool
    {
        return isset(static::$gateways[strtolower($gatewayName)]);
    }

    /**
     * Get gateway display names
     */
    public static function getGatewayNames(): array
    {
        return [
            'chip' => 'CHIP',
            'stripe' => 'Stripe',
        ];
    }
}
