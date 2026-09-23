# ServiceCrew — Task Tracker

Live status for every build task in `ServiceCrew-Plan-v2.md`, broken out from each phase's "Builds:" list. This file is the source of truth for what's done — conversation history can be compacted or lost, this file can't. Anyone (or any fresh session) opening this repo should be able to read this file and know exactly where the build stands, with zero dependence on chat memory.

**Update this file, don't just remember the status.** Mark a task's row when it's implemented, and again once the user has manually verified it — don't mark a task `done` without that manual check.

## Status values

| Value | Meaning |
|---|---|
| `not-started` | Nobody has picked this up yet. |
| `built` | Task implemented, awaiting the user's manual check. |
| `done` | User has manually verified it works. Task is complete. |

A phase's `Phase review` row is set to `done` once the user has reviewed the whole phase.

---

## Phase 1a — Foundation and admin catalog

| Task | Status |
|---|---|
| DB schema + activator/deactivator (no `sc_location`) | done |
| ~~Roles (`sc_crew_member`, etc.)~~ — merged into the row above | done |
| CPT `sc_service` + meta boxes | done |
| CPT `sc_crew` + meta boxes (type, address+ZIP, lat/lng, radius, photo, availability, time off) | built |
| Components with quantity counters + quantity-discount tiers | built |
| `class-service-crew-geocoding.php` wrapper + cache | not-started |
| Availability class | not-started |
| Pricing class (pure calc: components, quantity discounts, advance-payment tiers, minimum deposit) | not-started |
| Settings: business hours / holidays / arrival windows / travel buffer / overtime / timers | not-started |
| Setup wizard steps 1–3, 5–7 (basics, scheduling, address consent, first service, first crew member, finish checklist) | not-started |
| Services REST controller (admin-gated) | not-started |
| Crew REST controller (admin-gated; metadata-only, login provisioning arrives in 1d) | not-started |
| **Phase review** | not-started |

## Phase 1b-1 — Payments and email foundation

| Task | Status |
|---|---|
| `class-service-crew-payments.php` (gateway interface, payment records) | not-started |
| `class-service-crew-gateway-stripe.php` (Checkout session, webhook + signature verification, refunds) | not-started |
| Payment-request tokens + private pay page | not-started |
| Deposit / advance-discount tier logic wired to pricing class | not-started |
| `class-service-crew-emails.php` (templates, on/off toggles, `wp_mail`) | not-started |
| Payments step of the setup wizard (step 4) | not-started |
| Admin refund action | not-started |
| **Phase review** | not-started |

## Phase 1b-2 — Instant booking and date capacity

| Task | Status |
|---|---|
| `class-service-crew-customers.php` (find-or-create by email) | not-started |
| `class-service-crew-capacity.php` (pure calc: pooled hours, load, per-job date check, overtime) | not-started |
| `class-service-crew-bookings.php` (instant path, `awaiting_payment` → `confirmed` on webhook) | not-started |
| `class-service-crew-matching.php` (suggestions only) | not-started |
| Bookings / Customers / `calculate-price` / `available-dates` REST controllers | not-started |
| `[service_crew_booking]` shortcode (instant + emergency) | not-started |
| Minimal admin bookings list showing the suggestion | not-started |
| `awaiting_payment` cron expiry (never counts toward capacity) | not-started |
| **Phase review** | not-started |

## Phase 1c — Quotes, dispatch board, teams, vendor pricing

| Task | Status |
|---|---|
| Quote path + form (hardened photo upload: filetype/ext check, image-only, size cap, honeypot + rate limit) | not-started |
| `class-service-crew-notes.php` | not-started |
| `class-service-crew-assignments.php` | not-started |
| Dispatch board (suggestions, team assignment + lead, flags, overtime approval, admin-only surcharge, emergency approval, refund/balance actions, cancel/reschedule) | not-started |
| `class-service-crew-vendor-payments.php` | not-started |
| Quote timers + no-response timer in `class-service-crew-cron.php` | not-started |
| Quote-deposit payment link | not-started |
| "Assigned" / "reassigned" / "cancelled" / "updated" emails | not-started |
| **Phase review** | not-started |

## Phase 1d — Web Push and crew/vendor PWA

| Task | Status |
|---|---|
| VAPID keypair + vendored signer | not-started |
| `class-service-crew-push.php` (`sc_job_assigned` / `sc_job_canceled`, reassignment compound event) | not-started |
| Push controller | not-started |
| `class-service-crew-app-access.php` (App access box, invite, resend, reset, revoke) | not-started |
| PWA auth via Application Passwords | not-started |
| PWA screens (list, detail, Accept/Decline+reason, On the way, Start, Complete+note/photos, service worker, manifest) + `class-service-crew-pwa-loader.php` | not-started |
| Role-specific response shapes (employee: no money; vendor: own agreed amount + status) | not-started |
| "On the way" / "Started" / "Completed" emails | not-started |
| Stale-subscription sweep | not-started |
| **Phase review** | not-started |

## Phase 1e — CRM surfacing

| Task | Status |
|---|---|
| Customer detail (booking history with fulfiller, payment history, notes timeline w/ attachments) | not-started |
| Customer list/search | not-started |
| Notes UI on the board | not-started |
| **Phase review** | not-started |
| **End-of-Phase-1 full-codebase `wp-plugin-review` pass** | not-started |

---

## Built beyond the plan

- `[service_crew_services]` — read-only, publicly-visible shortcode listing published services (name/price/duration). Not in `ServiceCrew-Plan-v2.md`; the plan's only front-end shortcode is `[service_crew_booking]` (Phase 1b-2). Built by request ahead of that. `public/class-service-crew-services-shortcode.php`.

## Out of scope right now (not tracked here)

Phase 2 (invoicing, PayPal, vendor payout tooling), Phase 3 (full customer PWA), Phase 4 (multi-vertical) — see "Roadmap beyond Phase 1" in `ServiceCrew-Plan-v2.md`. Don't add rows for these until Phase 1 is done and they're actually scheduled.
