# Changelog

## 0.5.1 — 20 November 2026

- Claim coupon eligibility and usage in the central activation transaction before ordinary, scheduled and recovered plan grants. Hold refused confirmed payments as `coupon_review`. Enforce once-per-user under the coupon lock.
- Provide shared ownership-transfer payment-instrument revocation and notification; enforce current-owner consent in subscription renewal and serialize charging with workspace ownership changes.
- Keep small app compatibility bridges supported. Consumers must wire transfer/card UI hooks and update any native activation override (including SCount); updating Composer alone does not add app-owned hooks.

No schema or runtime-data migration. No SByte server-specific code.
