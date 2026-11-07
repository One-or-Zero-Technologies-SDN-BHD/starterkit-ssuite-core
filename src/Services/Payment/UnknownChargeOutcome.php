<?php

namespace Ssuite\Core\Services\Payment;

use RuntimeException;

/**
 * A charge whose result is not known yet (timeout, 5xx, still processing).
 * Carries the gateway's id for the charge when the gateway told us one, so a
 * retry can look that charge up instead of creating another.
 */
class UnknownChargeOutcome extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $gatewayRef = null)
    {
        parent::__construct($message);
    }
}
