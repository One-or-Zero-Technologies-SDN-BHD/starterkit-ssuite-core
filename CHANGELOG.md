# Changelog

## 0.5.3 — 2026-11-20

- Count legacy coupon redemptions without a payment link in the locked once-per-user check. A nullable historical link cannot authorize a second discounted plan grant.
- Regression coverage reproduces the second paid confirmation and requires coupon review without a new subscription or plan. Existing successful-payment replay stays idempotent.

## 0.5.2 — 20 November 2026

- Retain rejected-coupon projection cleanup intent until the actual workspace commit; retries finish cleanup without consuming another coupon use or granting a plan.
- Resume cleanup-only recovery when a confirmed callback re-enters a payment held for coupon review. Preserve newer manual projections.


## 0.5.1 — 20 November 2026

- Claim coupon eligibility and usage in the central activation transaction before ordinary, scheduled and recovered plan grants. Hold refused confirmed payments as `coupon_review`. Enforce once-per-user under the coupon lock.
- Provide shared ownership-transfer payment-instrument revocation and notification; enforce current-owner consent in subscription renewal and serialize charging with workspace ownership changes.
- Keep small app compatibility bridges supported. Consumers must wire transfer/card UI hooks and update any native activation override (including SCount); updating Composer alone does not add app-owned hooks.

No schema or runtime-data migration. No SByte server-specific code.
