# starterkit-ssuite-core

Shared backend Composer package for the [Laravel Livewire Starter Kit](https://github.com/One-or-Zero-Technologies-SDN-BHD/starterkit-laravel-livewire).

Every S-Suite app requires this package. A fix made here reaches an app on its next
`composer update ssuite/core` — no hand-copying between repositories.

## Graduated shell classes

Each class below lives here under `Ssuite\Core\…`. The app keeps a one-line subclass
with its original name (`App\…`), so routes, type hints, container bindings and tests
are unchanged. Package code always refers to the app's own classes, so an app can
override a method in its subclass when it genuinely needs to differ.

| Area | Classes |
|---|---|
| Plans | `Services\PlanResolver`, `Services\PlanGate` |
| Subscriptions | `Services\SubscriptionService`, `Services\SubscriptionGrace`, `Services\Billing\SubscriptionRenewalService` |
| Billing rules | `Support\Billing\` `Currency`, `Tax`, `CheckoutPricing`, `LockedPrice`, `PlanChange`, `OnlinePayments`, `OverLimitGuard`, `OverPlanLimit`, `PaymentVerification`, `SubscriptionActivation`, `SubscriptionSource`, `SubscriptionStatus`, `SubscriptionTerm`, `SubscriptionTransition` |
| Payments | `Services\Payment\` `PaymentGatewayFactory`, `PaymentGatewayInterface`, `SupportsRecurringPayments`, `UnknownChargeOutcome`, `Gateways\ChipGateway`, `Gateways\StripeGateway` |
| Sign-in and 2FA | `Support\AuthenticationSession`, `Http\Middleware\EnforceTwoFactor`, `Http\Controllers\Auth\TwoFactorController`, `Http\Controllers\Auth\TwoFactorChallengeController` |
| Team members | `Http\Controllers\TenantUserController`, `Http\Middleware\EnsureWorkspaceRoleDelegation` |

An app whose copy of a file had already drifted from the template keeps its own copy
until it is reconciled; it does not use the package class for that file.

## Team role delegation

`Http\Middleware\EnsureWorkspaceRoleDelegation` sits on the team member and team role
routes. A member who manages the team may only hand out, change, copy or remove what
their own role covers; the workspace owner and platform administrators are exempt. A
refused web request returns to the form with a message naming what the member lacks,
and a JSON request receives a 403. An app can override `describe()` to use its own
permission labels.

## Outbound HTTP timeouts

The Stripe and CHIP gateways send every request through `Http::outbound()`, which the
package registers unless the app already defines it.

| Setting | Default | Purpose |
|---|---|---|
| `services.outbound.timeout` | `10` | Seconds a gateway request may take |
| `services.outbound.connect_timeout` | `5` | Seconds to establish the connection |

## Central migration lock

All apps share one `central` database and each runs `migrate --force` on deploy. The
package listens for Laravel's migration events and holds a MySQL named lock
(`ssuite-central-migrate:<central database>`) while migrations run, so a second app
deploying at the same moment waits for the first instead of racing it on the shared
schema. The lock is released when migrations finish, or automatically by MySQL if the
process dies.

| Setting | Default | Purpose |
|---|---|---|
| `SSUITE_CENTRAL_MIGRATION_LOCK` | `true` | Turn the lock off |
| `SSUITE_CENTRAL_MIGRATION_LOCK_CONNECTION` | `central` | Connection the lock is taken on |
| `SSUITE_CENTRAL_MIGRATION_LOCK_TIMEOUT` | `600` | Seconds a deploy waits before failing |

Non-MySQL connections (for example SQLite in tests) are skipped.

## Package identity

The Composer package is `ssuite/core`, hosted in `One-or-Zero-Technologies-SDN-BHD/starterkit-ssuite-core`. Require `^0.5.0` for the renamed package. Release 0.5.0 changes package identity only: PHP classes stay under `Ssuite\Core`, and configuration keys, routes and database behavior are unchanged. The package declares no `replace` or `provide` alias. Private GitHub repository access remains necessary for deployment.


## Coupon settlement and ownership consent (20 November 2026)

`Support\Billing\CouponSettlement` claims a coupon in the central activation transaction before an entitlement is granted. Refused confirmed claims enter `coupon_review`; normal callback retries do not activate that state. Own-transaction replay is idempotent, while caps, validity/activity and the existing once-per-user policy apply to new claims. Deferred funding and interrupted projection recovery follow the same rule. There are no checkout reservations or schema changes.

`Support\Billing\PaymentInstruments` is the shared removal path for ordinary card removal and ownership transfers. App transfer callbacks call `ownershipChanged($tenant, $outgoingId, $incomingId)` under the existing workspace lock, before publishing ownership. Local consent is revoked first, subscription renewal links are disabled/detached, and gateway cleanup plus email notification to both owners follows the actual app commit. Gateway cleanup is best effort; local consent stays revoked if it fails. The renewal service uses the same workspace lock and checks the instrument's current owner before charging.

These changes are released in **0.5.3** and need app-owned controller hooks. Consumer lockfiles and local core-source edits are separate. SCount's independent activation service also needs the coupon-grant changes; merely updating its package is insufficient. The template's `docs/BILLING-COUPON-OWNERSHIP-FIXES-2026-11-20.md` lists pickup paths and regression coverage. Consumer rollout receipts are recorded in [the template report](https://github.com/One-or-Zero-Technologies-SDN-BHD/starterkit-livewire/blob/main/docs/BILLING-SECURITY-ROLLOUT-2026-11-20.md). No production deployment or live-provider acceptance is claimed here.
