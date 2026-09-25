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
| ~~CPT `sc_service` + meta boxes~~ — replaced by the custom admin app row below (native UI removed, `show_ui => false`) | done |
| CPT `sc_crew` + meta boxes (type, address+ZIP, lat/lng, radius, photo, availability, time off) | built |
| Services custom admin app (list/create/edit, flat-vs-per-unit pricing, add-ons; category parents have pricing/add-ons actually cleared, not just hidden) + Services REST controller (admin-gated, pulled forward as the app's data layer) | built |
| Components (add-ons) with quantity counters — no per-add-on discount tiers | built |
| Discounts settings screen (advance-payment tiers config; applying them at checkout is Phase 1b-1) | built |
| `class-service-crew-geocoding.php` wrapper + cache | built |
| Availability class | built |
| Pricing class (pure calc: components, quantity discounts, advance-payment tiers, minimum deposit) | built |
| Settings: business hours / holidays / arrival windows / travel buffer / overtime / timers | built |
| Setup wizard steps 1–3, 5–7 (basics, scheduling, address consent, first service, first crew member, finish checklist) | built |
| Crew REST controller (admin-gated; metadata-only, login provisioning arrives in 1d) | built |
| **Phase review** | not-started |

## Phase 1b-1 — Payments and email foundation

| Task | Status |
|---|---|
| `class-service-crew-payments.php` (gateway interface, payment records) | built |
| `class-service-crew-gateway-stripe.php` (Checkout session, webhook + signature verification, refunds) | built |
| Payment-request tokens + private pay page | not-started |
| Deposit / advance-discount tier logic wired to pricing class | built |
| `class-service-crew-emails.php` (templates, on/off toggles, `wp_mail`) | not-started |
| Payments step of the setup wizard (step 4) | not-started |
| Admin refund action | not-started |
| **Phase review** | not-started |

## Phase 1b-2 — Instant booking and date capacity

| Task | Status |
|---|---|
| `class-service-crew-customers.php` (find-or-create by email) | not-started |
| `class-service-crew-capacity.php` (pure calc: pooled hours, load, per-job date check, overtime) | not-started |
| `class-service-crew-bookings.php` (instant path, `awaiting_payment` → `confirmed` on webhook) | built |
| `class-service-crew-matching.php` (suggestions only) | not-started |
| Bookings / Customers / `calculate-price` / `available-dates` REST controllers | not-started |
| `[service_crew_booking]` shortcode (instant + emergency) | not-started |
| Minimal admin bookings list showing the suggestion | built (no suggestion — `class-service-crew-matching.php` not started) |
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

- `[service_crew_services]` — interactive column-browser shortcode (Service → Sub-service → Add-ons → live running Total). Hover-preview now shows a category's *entire* sub-tree at once (nested/indented, styled scrollbar), and every node in it — any depth — is directly clickable (`selectPreviewPath()`), never requiring a prior click into an intermediate column. Sticky total-column footer (styled scrollbar on the cart-card list; subtotal / best-case advance-payment discount / tax (exclusive or inclusive "deduction" mode) / grand total / minimum-to-book, pinned below the scroll — no duration line, removed by request). Not in `ServiceCrew-Plan-v2.md`; the plan's only front-end shortcode is `[service_crew_booking]` (Phase 1b-2), which this anticipates the selection/pricing-preview half of — no submit action, since no booking/payment backend exists yet. `public/class-service-crew-services-shortcode.php`, `public/js/booking-widget.js`, `public/css/booking-widget.css`. Originally a static read-only nested list; upgraded twice more by request since (prominent sub-service icon + direct multi-level selection + sticky/styled summary; then full-subtree preview + tax mode + tiered deposit + scrollbar styling + duration removed).
- Appearance settings screen (`includes/class-service-crew-appearance.php`) — admin-configurable color palette (12 tokens: general/interaction/total-column) for the `[service_crew_services]` widget above, via the same custom admin app + REST pattern as Services/Discounts.
- Site-wide tax rate + mode (`tax_rate_percent`/`tax_mode` on `Service_Crew_Settings`, admin UI in its Tax card) — not in `ServiceCrew-Plan-v2.md` (the plan has no tax concept at all), added by request alongside the widget's sticky total summary above. `tax_mode` is `exclusive` (add on top, default) or `inclusive` (prices already include tax — "deduct it out" instead). `Service_Crew_Pricing::calculate_tax_amount()` is the pure-calc counterpart for when a real checkout exists.
- Frontend design-token pass on `public/css/booking-widget.css` — radius (`--sc-w-radius-lg/md/sm/xs/pill`), shadow (`--sc-w-shadow-flyout`), and transition (`--sc-w-transition-fast/flyout`) custom properties scoped to `.sc-booking-widget`, replacing repeated magic numbers, so any future screen sharing that shell (the `[service_crew_booking]` stepper) inherits a consistent scale. No visual change; colors were already tokenized via `--sc-w-*`/`Service_Crew_Appearance`.
- Minimum-deposit-by-booking-amount brackets (`minimum_deposit_tiers` on `Service_Crew_Settings`, admin UI in its "Minimum deposit" card) — replaces the plan's single flat minimum-deposit percentage (Confirmed Decisions → Payments) with brackets keyed by booking amount, by request. `Service_Crew_Pricing::get_matching_deposit_tier()` is the pure-calc lookup; the widget's sticky summary shows the resolved "Minimum to book" line.
- `[service_crew_booking]` — animated four-step booking flow (Service → Date & Time → Payment → Thank you). Step 3 now creates a real `sc_bookings` row and redirects to a real Stripe Checkout session (`class-service-crew-bookings.php`, `class-service-crew-bookings-controller.php`, both built above/below); Step 4 is only ever reached by the customer coming back from Stripe (polls `GET /bookings/{id}/payment-status` until the webhook confirms it). **Still narrower than the Phase 1b-2 shortcode row above — do not mark that row `built` from this entry:**
  - **One service per booking, enforced client-side before Step 2** — `sc_bookings.service_id`/`sc_booking_components` have no way to represent more than one top-level service on one booking, so a real booking only ever contains the single leaf service (plus its own add-ons) left in Step 1's cart, not that step's full multi-item preview cart.
  - **No `class-service-crew-customers.php`** — `customer_name`/`email`/`phone` are stored directly on the booking row (`customer_id` stays null), per the schema's own "source of truth until Customers exists" note.
  - **No `class-service-crew-capacity.php` or `class-service-crew-matching.php`** — Step 2 (Date & Time) still only greys out non-business days/holidays from `Service_Crew_Settings`; every open business day is bookable regardless of crew load, and `is_emergency` is always 0 (no emergency path/UI).
  - **Deposit only, no tier picker** — the customer always pays exactly the admin's resolved minimum-deposit amount now (`Service_Crew_Pricing::build_payment_options()`'s first, `is_minimum` row); the plan's full "minimum, plus every tier above it" checkout selector isn't built.
  - **No `/calculate-price` or `/available-dates` REST endpoints, no `awaiting_payment` cron expiry** — still separate not-started Phase 1b-2 rows above.
  - Geocoding is best-effort (never blocks the booking, per the plan) via the existing `class-service-crew-geocoding.php` choke point.
  `public/class-service-crew-booking-shortcode.php`, `public/js/booking-flow.js`, `public/css/booking-flow.css`, `includes/class-service-crew-bookings.php`, `includes/class-service-crew-bookings-controller.php`. `Service_Crew_Services_Shortcode::get_tree()`/`build_pricing_payload()` and `Service_Crew_Appearance::build_color_css()` were made public statics so this shortcode reuses them rather than duplicating tree-building or color-CSS logic.
- Bookings admin screen (`includes/class-service-crew-admin-app.php`'s new "Bookings" submenu, `admin/js/app-bookings.js`) — a table of the most recent bookings (service, customer, date/window, payment, flags) with an inline status dropdown, by request ("this should be table form because we update status and assign to employees"). **Status editing only — no employee/vendor assignment**, since there's no Crew REST controller yet (Phase 1a, still `not-started`) to even list who could be assigned, and assignment itself is `class-service-crew-assignments.php` (Phase 1c, not started). The dropdown is deliberately limited to `Service_Crew_Bookings::ADMIN_SETTABLE_STATUSES` (`awaiting_payment` / `confirmed` / `completed` / `cancelled`) — not the plan's full status list — since the quote statuses don't apply to an instant booking and the crew-workflow statuses (`assigned`/`on_the_way`/`in_progress`) are meaningless before assignments exist. New admin-gated `PUT /service-crew/v1/bookings/{id}/status` route on `Service_Crew_Bookings_Controller`.

## Out of scope right now (not tracked here)

Phase 2 (invoicing, PayPal, vendor payout tooling), Phase 3 (full customer PWA), Phase 4 (multi-vertical) — see "Roadmap beyond Phase 1" in `ServiceCrew-Plan-v2.md`. Don't add rows for these until Phase 1 is done and they're actually scheduled.
