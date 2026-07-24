# ssuite-core

The **shared backend shell** for the s-suite, distributed as a Composer package so a fix lands
**once** and reaches every app on the next `composer update` — the backend companion to
`neophyteheaven/ssuite-ui` (which carries the Blade/UI layer).

This is the distribution decision recorded in `starterkit-livewire/docs/TEMPLATE-PARITY.md` §3:
a versioned Composer package, **not** publishable stubs, subtree, or a copy-script.

## Status

**Standalone repo, graduating incrementally.** Graduated so far:

| Class | Was | Notes |
|---|---|---|
| `Ssuite\Core\Services\PlanResolver` | `App\Services\PlanResolver` | Single source of truth for "which Plan does this workspace have?". Each app keeps a thin `App\Services\PlanResolver` subclass so callers are unchanged. |

## How an app consumes it

**Local dev (Herd monorepo layout)** — a path repository, since every app sits next to this folder:

```jsonc
// <app>/composer.json
"repositories": [
  { "type": "path", "url": "../ssuite-core", "options": { "symlink": true } }
],
"require": { "neophyteheaven/ssuite-core": "^0.1" }
```

**CI / production (Forge, separate deploys)** — swap the path repo for the VCS repo once this is
pushed to its git host (a deploy key gives CI read access):

```jsonc
"repositories": [
  { "type": "vcs", "url": "https://github.com/neophyteheaven/ssuite-core.git", "no-api": true }
]
```

No app code changes between the two — only the `repositories` entry. Then `composer update neophyteheaven/ssuite-core`.

## How a file graduates

1. Confirm it's byte-identical + stable across apps (`php artisan ssuite:shell-parity --target=…` in starterkit-livewire).
2. Move the implementation here under `Ssuite\Core\…`; keep an `App\…` subclass/alias in each app so callers don't change.
3. `composer update neophyteheaven/ssuite-core`; run the app suite green.
4. Drop the file from that app's `shell-manifest.txt` (now package-managed, no longer hand-propagated).

## Publishing

`composer.json` here is already a publishable package. To host it:

```bash
git remote add origin git@github.com:neophyteheaven/ssuite-core.git
git push -u origin main
git push origin 0.1.0        # the tag
```

Then point each app's `repositories` at the VCS url (above) and `composer update`.
