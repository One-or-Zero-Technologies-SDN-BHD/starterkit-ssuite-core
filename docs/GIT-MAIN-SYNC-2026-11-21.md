# Core main synchronization — 21 November 2026

The primary checkout was five published commits behind on master. Its local coupon, ownership, notification and version work already matched the published 0.5.3 source; stale local documentation was retained in the recovery snapshot while the newer published changelog and rollout link were kept.

The two remaining CHIP/Stripe gateway verification changes are integrated into main. Tests exercised the candidate package directly through thin app aliases, then exercised the same candidate with the existing template adapters: **141 cases and 682 assertions passed in each mode**, with no errors, failures or skips. PHP syntax checks and scoped Pint passed; Composer metadata validated. Provider HTTP requests were mocked and database tests used newly created disposable schemas and private caches.

This is selected billing coverage hosted by starterkit-livewire, not a standalone package-wide suite or live-provider acceptance. No app lockfiles, tagged releases, runtime databases or production environments were changed. The latest existing release remains v0.5.3; publishing main does not create a new release.

The primary checkout and completed release branch are consolidated into main, which is the repository default. Recovery bundles, exact source snapshots, raw test results and source fingerprints are retained privately under the owner's `.codex/archives/ssuite-core-main-sync-*` directory. Existing tag identities and contributor/model history are retained.
