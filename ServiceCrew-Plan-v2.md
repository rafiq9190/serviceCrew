# ServiceCrew — Implementation Plan v2 (date-based door-to-door booking, payments, teams, vendors)

## Context

This revision keeps every locked decision from the earlier plan (file/class naming, REST namespace, Web Push/VAPID design, Application-Passwords PWA auth, snapshot-immutable booking fields, out-of-band quotes, always-manual assignment confirmation) and replaces the parts that the latest planning sessions changed. Everything below was confirmed with the owner in conversation.

**Replaced from the earlier revision**

| Earlier | Now |
|---|---|
| Time-slot picker driven by employee availability at booking | Customer picks a **date + arrival window**. Date availability comes from pooled employee capacity (see Scheduling). |
| Vendor-only slot shows "pending confirmation" to the customer | Customer always sees "confirmed" for a normal booking. Only **emergency** bookings are pending. |
| Vendor confirmed by admin phone call ("contacted, confirmed") | Vendor is confirmed by **accepting in the PWA**. |
| Declined job clears crew *and* schedule | Declined job **keeps date and window**, only the person is cleared. |
| Payments in Phase 2 | **Stripe payments are Phase 1** (deposit required to book). PayPal later, same wrapper. Invoicing stays Phase 2. |
| "No area check" was open | No "we don't service this area" message ever shown. Out-of-coverage is an admin-side flag. |
| Vendor PWA access open question | **Vendors get the PWA**, with a different data view from employees. |
| No customer notifications | Status **emails** (no status page, no door code, no self-service). |

**Carried forward unchanged:** door-to-door only (no `sc_location`), employees and vendors share one crew record shape with a `type` field, vendors tracked individually, no heatmap analytics in Phase 1, geocoding behind a thin wrapper, deployment inside `wp-content/plugins/serviceCrew/`.

**Also changed since v2 was first written:** UI framework switched from Tailwind to **Bootstrap** (2026-09-22, no code existed yet so no migration was needed) — build-time bundled via the same npm/Sass pipeline, never a CDN `<link>`/`<script>`, same as Tailwind was. Use the bundled build (`bootstrap.bundle.min.js`, includes Popper) rather than pulling Popper separately.

**Out of scope / flags (unchanged):** the unrelated `rivora` plugin is ignored. `CLAUDE.md` needs an update once Phase 1a code lands. `code.txt` in the repo root holds a secret-looking string worth checking separately.

---

## Confirmed decisions

### Business and addresses
- Every job happens at the customer's address. Address **and ZIP/postal code** are both collected.
- Geocoding: **Nominatim now, Google Maps later**, behind `class-service-crew-geocoding.php`. Results cached (Nominatim is ~1 request/second). Disclosed in `readme.txt` and accepted by the admin in the setup wizard.
- Address lookup outcomes: full address found → use it. Full address fails but ZIP works → match using the ZIP centre and flag **"address approximate"**. Both fail → booking still goes through, flagged **"address needs review"**.
- No customer-facing "we don't service this area". If the address is outside every crew radius, the board shows **"outside coverage"** and the admin contacts the customer (refund available if they prepaid).

### Crew records (employees and vendors)
- One record shape: type, contact, email, base address + ZIP, geocoded lat/lng, coverage radius (`null` = unlimited, default for employees; required for vendors), weekly availability incl. split shifts, time off, services offered, **photo**.
- Vendors: one record per person, **one job per slot** capacity, no company layer.

### Services and pricing
- Service → sub-services (components) with required flag.
- A component can have a **quantity counter** (− / +): min, max, default, unit price, unit duration. Total = unit × quantity for both price and time.
- Each service has **crew needed** (default 1), adjustable per booking.
- **Quantity discounts:** admin-set tiers per component (e.g. 3 or more → 10% off per unit, or fixed amount).
- All price maths runs on the server (`POST /calculate-price`); the browser never duplicates the formula.

### Scheduling and capacity
- Admin sets weekly business hours (default Monday–Friday, 9 to 6), holidays / closed dates, **arrival windows** (e.g. morning 9–12, afternoon 12–3, evening 3–6) and a **fixed travel buffer** between an employee's jobs.
- Customer picks a **date** and an **arrival window**. The window is a preference, not a slot; it never blocks anyone.
- **Employee capacity per date** = sum of working hours of each available employee that day (own weekly template minus time off).
- **Load per date** = sum over bookings covering that date of (job hours that day × crew needed) plus the travel buffer per job-person. Bookings need no assigned person to count.
- A start date is **disabled** when any day the job would cover cannot fit the job's per-day load. Vendors are **not** counted; they are the overflow.
- Multi-day jobs run on consecutive business days from the chosen date (weekends and holidays skipped), same window each day.
- **No admin-set booking limit** per day. Admin still closes dates manually (holidays, days off).
- **Emergency booking:** on a disabled date the customer can tick "emergency" and continue with the normal steps (including deposit). The customer sees **no surcharge or extra price**. The booking is saved as `pending_approval`, flagged "overload", and the customer is told it will be confirmed shortly.
- **Overtime:** admin setting (allow on/off + max extra hours per employee per day). Overtime is **approved case by case** on the board (typically for emergency jobs), tracked in hours per employee and per job, and counts toward capacity only once approved. An optional **overtime surcharge** (percentage or fixed) is **admin-only**: applied, custom, or waived per job. It is never shown to the customer and no email is sent automatically. The admin tells the customer directly and can collect the extra amount by payment link or mark it collected.

### Payments
- **No payment, no booking.** The customer must pay at least the **minimum deposit**, an admin-set **percentage** of the job total.
- **Advance-payment discount:** admin-set **tiers** pairing "percent paid now" with a discount (e.g. 50% → 2% off, 100% → 5% off). Below the first tier, no discount.
- Checkout shows the minimum, each tier option, and the resulting price for each.
- **Stripe first** (Stripe-hosted Checkout, so card data never touches the site); **PayPal later** through the same gateway interface. Keys are entered in the setup wizard (test/live mode toggle, connection test). Secret key is stored carefully and never echoed back in full (a `wp-config.php` constant is supported as an alternative).
- The remaining **balance** is collected by the admin: marked collected, or a payment link sent to the customer.
- **Refunds:** admin-only, full or partial, with a reason, logged as a system note. Crew never collect money.
- If Stripe is not configured, instant booking is unavailable; the quote form still works.

### Quotes
- Separate form, **no service, no payment**: photos, title, description, preferred date + arrival window, address + ZIP, phone, plus **name and email** (needed for emails and the customer record).
- Admin reviews, sets service (optional), price, duration and crew needed, quotes **outside the system**, marks it `quoted`.
- On agreement the admin sets date + window and sends a **deposit payment link**. **Payment confirms the quote** (same "no payment, no booking" rule). An unpaid link does not hold the date and counts toward capacity only once paid.
- Quote **validity is admin-set** (default 7 days, changeable per quote), with a reminder before it lapses; then it shows `expired` and can be reopened with a new date.
- Rejected: admin marks it with a reason; it closes. Accepted but unscheduled: reminder after an admin-set number of days (default 3), then it expires like any quote.

### Dispatch, teams and vendors
- Every booking lands on the dispatch board. The system **suggests** the best fit (nearest, least-loaded qualifying employee; vendors when no employee fits, nearest first). It never assigns automatically.
- A booking has a **list of assignments**, not one crew id. Single-person jobs work as before (that person is automatically the lead). Team jobs show "2 of 3 assigned"; a declined place shows "needs 1 more". Employees and vendors can be mixed on one team.
- **Lead** controls On the way, Start and Complete and adds the completion note and photos. If the lead declines, the admin picks a new lead.
- **Vendor price:** an **agreed amount** is entered on each vendor assignment and is **required before assigning**. After the vendor accepts in the PWA, the admin can pay it **in part or in full** at any point; payments are recorded (date, method, note). Status: pending / partially paid / paid. Payment happens outside the system; the plugin only tracks it.
- The admin sees customer total, vendor agreed amount and margin. Only admin sees this.
- Conflicts and overlaps are **warnings, never blocks**.

### Crew app (PWA)
- **Employees see no money at all.** **Vendors see only their own agreed amount, their payment status and amount paid so far.** No customer price, deposit, balance, discount or surcharge, ever. Enforced by the **server** (separate response shapes per role), verified by direct API calls in testing.
- Flow per job: Accept (or Decline with a reason) → **On the way** → Start → Complete. "On the way" exists because a first visit is sometimes needed before work begins; Start is tapped only when work actually begins.
- Job list is server-filtered to the person's own assignments. Push denied still allows manual list refresh.
- **App access box on each crew form:** on/off toggle, username (editable, unique), status (not invited / invited / active / revoked), last login, and buttons **Send invite / Resend / Reset password / Revoke**. Needs a valid email on the record.
- Invite email = app link + username + a **one-time set-password link** that expires after a few days. **No password is ever emailed.** Turning access on creates a `wp_users` account with the `sc_crew_member` role (blocked from wp-admin).
- Revoke: login stops, Application Passwords and devices removed, push subscriptions deleted, history kept. Warn first if upcoming jobs exist.

### Emails
Each email can be switched on/off and its wording edited in Settings. No status page, no door code, no self-service cancel/reschedule.

| Email | To | When |
|---|---|---|
| New booking / new quote / emergency booking | Admin | On submit |
| Booking confirmation | Customer | On paid booking (emergency says "we'll confirm shortly") |
| Quote received | Customer | On quote submit |
| Assigned (names, photos, staff/vendor, arrival window) | Customer | When **everyone** on the team has accepted (vendors only after PWA accept) |
| Reassigned | Customer | New person(s) confirmed |
| On the way | Customer | Lead taps On the way |
| Started | Customer | Lead taps Start |
| Completed | Customer | Lead taps Complete |
| Booking updated (date/window changed) | Customer | Admin reschedules |
| Cancelled | Customer | Admin cancels |
| Payment received / refund issued | Customer | On Stripe result / admin refund |
| No response, quote expiring, accepted-not-scheduled | Admin | Timers below |

---

## Data model changes (field-level design at implementation)

- **Dropped:** `sc_location`, location-based capacity.
- **`sc_bookings`:** address + ZIP, geocoded lat/lng, geocode quality (`exact` / `zip` / `failed`), preferred date, arrival window, duration in days, `is_emergency`, `crew_needed`, customer total, quantity-discount and advance-discount snapshot, `source` (instant / quote). **Statuses:** `awaiting_payment`, `requested`, `quoted`, `quote_expired`, `quote_rejected`, `pending_approval` (emergency), `confirmed`, `assigned`, `on_the_way`, `in_progress`, `completed`, `cancelled`. **Flags (not statuses):** `outside_coverage`, `address_approximate`, `address_review`, `overload`, `declined`, `cancelled_by_crew`, `no_response`, `unassignable`, `assignee_unavailable`, `needs_more_time`, `paid_needs_action`.
- **`sc_booking_components`:** add quantity, unit price and unit duration snapshots.
- **New `sc_booking_assignments`:** booking, crew, role (lead/member), status (`proposed`, `accepted`, `declined`, `cancelled_by_crew`), decline reason, assigned/responded timestamps, `agreed_amount` (vendors only). Decline history kept so the same person is not re-suggested.
- **New `sc_payments`:** booking, kind (`deposit` / `balance` / `extra` / `surcharge`), amount, status, provider, provider reference, hashed token, expiry. Plus a refunds table.
- **New `sc_vendor_payments`:** assignment, amount, paid date, method, note.
- **New closed-dates / holidays** storage, and overtime records per assignment (hours, approved by, surcharge decision).
- **`sc_notes`:** unchanged from earlier plan (nullable `attachment_id`), also stores quote description/photos and system notes for payment, refund, overtime and vendor-payment events.
- **Crew post meta:** photo, app-access status, linked `wp_users` id.

---

## Revised phasing

### Phase 1a — Foundation and admin catalog
**Builds:** database + activator/deactivator (no `sc_location`), roles, CPTs `sc_service` / `sc_crew` with meta boxes (type, address + ZIP, geocoded lat/lng, radius, photo, availability, time off), **components with quantity counters and quantity-discount tiers**, geocoding wrapper (+ cache), availability class, pricing class (pure calc: components, quantity discounts, advance-payment tiers, minimum deposit), business-hours / holidays / arrival-windows / travel-buffer / overtime / timer settings, and the **setup wizard** (below). Admin-gated Services/Crew REST controllers.
**Note:** crew records are metadata-only here; login provisioning arrives in 1d (`POST /crew` gains the App access behaviour, same endpoint).
**Why first:** no dependencies; proves the riskiest schema pieces (component hierarchy with quantities, availability shape, geocoding + radius maths).

### Phase 1b-1 — Payments and email foundation
**Builds:** `class-service-crew-payments.php` (gateway interface, payment records), `class-service-crew-gateway-stripe.php` (Checkout session, webhook with **signature verification** as its permission check, refunds), payment-request tokens and the private pay page, deposit / tier logic wired to the pricing class, `class-service-crew-emails.php` (templates, on/off toggles, `wp_mail`), Payments step of the wizard, admin refund action.
**Why here:** a booking cannot complete without payment, so Stripe must exist before the booking form. Independently demoable with a test-mode payment request.

### Phase 1b-2 — Instant booking and date capacity
**Builds:** `class-service-crew-customers.php` (find-or-create by email), `class-service-crew-capacity.php` (pure calc, unit-testable: pooled hours, load, per-job date check, overtime), `class-service-crew-bookings.php` (instant path, `awaiting_payment` → `confirmed` on webhook), `class-service-crew-matching.php` (suggestions only), Bookings/Customers/`calculate-price`/`available-dates` REST controllers, `[service_crew_booking]` (instant + emergency), minimal admin bookings list showing the suggestion.
**UI requirements:** live price/duration from `POST /calculate-price` only; on a date-filled race, re-fetch dates and show "that date just filled" before any charge; emergency option only on disabled dates; `awaiting_payment` rows expire via WP-Cron and never count toward capacity.

### Phase 1c — Quotes, dispatch board, teams, vendor pricing
**Builds:** quote path and form (photo upload hardened: `wp_check_filetype_and_ext()`, image-only allowlist, size cap, `media_handle_upload()`, honeypot + rate limit), `class-service-crew-notes.php`, `class-service-crew-assignments.php`, dispatch board (suggestions with distance/workload, team assignment with lead, flags, overtime approval and admin-only surcharge, emergency approval, refund and balance-collection actions, cancel/reschedule tools), `class-service-crew-vendor-payments.php`, quote timers (expiry, reminders) and the no-response timer in `class-service-crew-cron.php`, quote-deposit payment link, "assigned" / "reassigned" / "cancelled" / "updated" emails.

### Phase 1d — Web Push and crew/vendor PWA
**Builds:** VAPID keypair + vendored signer, `class-service-crew-push.php` (`sc_job_assigned` / `sc_job_canceled`, compound event on reassignment), push controller, `class-service-crew-app-access.php` (App access box, invite, set-password link, revoke), PWA auth via Application Passwords, PWA (list, detail, Accept / Decline + reason, On the way, Start, Complete + note/photos, service worker, manifest, `class-service-crew-pwa-loader.php`), **role-specific response shapes** (employee: no money; vendor: own agreed amount + status), "on the way" / "started" / "completed" emails, stale-subscription sweep.

### Phase 1e — CRM surfacing
Customer detail (booking history with who fulfilled each job, payment history, notes timeline including attachments), customer list/search, notes UI on the board.

### Roadmap beyond Phase 1
Phase 2: invoicing and PayPal (through the gateway interface), vendor payout tooling if it becomes a real need. Phase 3: full customer PWA. Phase 4: multi-vertical.

---

## Detailed user flows

### Admin — first-run setup wizard (Phase 1a, extended in 1b-1 / 1d)
Runs right after activation (HTTPS notice if missing; plain-permalinks notice). Progress saved at every step, Back/Next, reopenable later from the plugin menu as "Setup wizard".
1. **Business basics:** name, timezone, currency, logo, brand colour, admin alert email.
2. **Scheduling:** weekly hours (default Mon–Fri 9–6), holidays, arrival windows, travel buffer, overtime allow + max hours, no-response wait (default 12 h), quote validity (default 7 days), quote-accepted-not-scheduled reminder (default 3 days).
3. **Address lookup consent:** explains that addresses go to Nominatim; tick to accept; "test an address" must pass. **Cannot be skipped.**
4. **Payments (skippable):** Stripe keys, test/live, connection test, minimum deposit %, advance-payment discount tiers. Skipped → instant booking disabled, quote form still works.
5. **First service:** price, duration, crew needed; then components (required flag, optional quantity counter, quantity-discount tiers).
6. **First crew member (skippable):** type, contact + email, address + ZIP, radius, hours, time off, photo. No login yet.
7. **Finish checklist:** shows what is done/missing (e.g. no crew yet, Stripe not connected).

### Customer — instant booking
1. Choose service → choose sub-services (required locked on; optional ones tick or − / + counter) → live price, time and quantity discount lines.
2. Enter address + ZIP (optional "use my location"). Lookup outcome handled as in Decisions; never blocks.
3. Pick a date (open business days only; full dates disabled) → pick arrival window.
4. On a disabled date: optional **emergency** tick, then continue.
5. Contact details.
6. Checkout: minimum deposit, tier options with resulting prices → pay via Stripe.
7. Server re-checks the date **before** creating the Checkout session. On success (webhook): booking `confirmed` (or `pending_approval` if emergency), confirmation email to customer, alert email to admin.

### Customer — quote request
1. Toggle "Request a Quote" → photos, title, description, preferred date + window, address + ZIP, phone, name, email → submit.
2. "Request received" screen + confirmation email. No price, no payment.
3. Everything after that happens outside the system until the admin sends the deposit link.

### Dispatcher — daily workflow (Phase 1c)
1. Booking appears on the board (email alert sent). Card shows date, window, address, total, paid, balance, flags.
2. **Suggestions** (employees first, vendors as overflow) with distance and workload. Pick one or several (team); mark the lead.
3. **Vendor:** enter the agreed amount first; the vendor gets a push and accepts in the PWA.
4. Customer gets the "assigned" email when everyone has accepted.
5. Track status changes from the crew; add notes; collect the balance (mark collected or send link); pay vendors in part or in full after acceptance; refund when needed.
6. **Emergency:** review the overload flag, approve or decline, decide overtime and any surcharge, use the vendor list.
7. **Quotes:** review → price → send quote outside → mark quoted → on agreement set date + window and send deposit link → payment confirms → same assignment flow.

### Crew and vendor — PWA (Phase 1d)
Invite email → set password → install app → allow push (explicit action) → assignment push → job detail (customer name, tap-to-call phone, address + map link, service and sub-services with quantities, total time, notes, team and lead; vendors also see their own agreed amount and payment status) → Accept/Decline → On the way → Start → Complete (lead adds note and photos). Reassigned or cancelled jobs disappear with a push.

### CRM (Phase 1e)
Customer detail shows booking history (with fulfilling people), payments and refunds, and the full notes timeline from the same `sc_notes` rows the board writes to.

---

## When things go wrong

| Situation | What happens |
|---|---|
| **Professional rejects** | Job keeps its date and window, returns to unassigned, flagged "declined" with the reason. That person is left out of suggestions for the job. Date capacity still counts it. No customer email until a new assignment. |
| **Professional doesn't respond** | After the admin-set wait (default 12 h) the card is flagged "no response" and the admin gets one email. Nothing is reassigned automatically. |
| **No professional available** | Flagged "unassignable" (distinct from an untriaged job). Suggestions extend to vendors. Admin contacts the customer; refund if the job can't be served. |
| **Customer cancels** | By phone to the admin. Admin cancels: job leaves the crew list with a push, capacity is freed, refund via the button (full/partial), cancellation email to the customer. |
| **Customer reschedules** | Admin edits date/window. Capacity re-checked (warning if the new date is full), crew get a push, assignees who are unavailable are flagged, customer gets an "updated" email. |
| **Professional cancels after accepting** | Treated like a decline, marked "cancelled by crew" with a reason, flagged urgent if the job is within 24 hours. Customer is only told when a new person is confirmed. |
| **Contractor becomes unavailable** | Time off added after assignment flags affected jobs "assignee unavailable". Nothing is unassigned automatically. |
| **Job takes longer than expected** | Lead taps "need more time" with a note. Job is flagged; admin decides: extend, second day, overtime, surcharge. Capacity updates when the admin edits the duration. |
| **Multiple professionals required** | Team job: card shows "N of M assigned" and "needs K more". A declined place reopens only that place. Assigned email waits for everyone to accept. |
| **Customer requests additional work** | Crew add a note (they cannot see prices). Admin prices it, adds an extra line to the booking, collects it by payment link or marks it collected. |
| **Quote expires** | After the admin-set validity (default 7 days) with a reminder before; shows `expired`; admin can reopen with a new date. |
| **Quote rejected** | Admin marks it with a reason; it closes. |
| **Quote accepted but not scheduled / deposit unpaid** | Stays `quoted`; admin reminder after the set days; expires like any quote. An unpaid link never holds a date. |
| **Payment fails** | No booking is confirmed and no date is held. Customer sees Stripe's error and can retry or use another card. `awaiting_payment` rows expire via cron. |
| **Payment succeeds but the booking can't be finalised** | The Stripe webhook creates/confirms it anyway. If the date filled in the meantime, it is flagged `paid_needs_action` for the admin (reschedule or refund). Webhooks are idempotent. |
| **Booking conflicts with another booking** | Overlaps for the same person are shown as warnings on the board, never blocked. The date is re-checked before any charge. |
| **Paid booking nobody can take** | Flagged `paid_needs_action`; admin reschedules, finds a vendor, or refunds. |

---

## Conventions every phase must follow

- File naming `class-service-crew-{feature}.php` → `Service_Crew_{Feature}` (e.g. `class-service-crew-gateway-stripe.php` → `Service_Crew_Gateway_Stripe`). Bootstrap file has no feature logic.
- Directories: `includes/`, `admin/`, `public/`, `api/`, `pwa/`.
- Every REST endpoint has a `permission_callback` (never `__return_true` for writes) and an `args` schema with sanitize/validate callbacks. The Stripe webhook's permission check is signature verification.
- Sanitize on input, escape late, `$wpdb->prepare()` on all SQL, nonces on forms, capability checks, transient caching invalidated on write.
- **Money visibility:** crew responses are built by role-specific serializers; employee responses contain no money fields, vendor responses contain only their own agreed amount and payment status.
- **Tokens** for payment links are stored hashed, expire, and are single-purpose.
- **WP.org disclosures in `readme.txt`:** Web Push relay, Nominatim address lookup, Stripe. Application-Passwords PWA auth documented. No phone-home without consent. Bootstrap build-time only, no CDN JS/CSS.
- Additional review items: no deprecated functions, WP-Cron not system cron, no inline JS/CSS in PHP, sanitize `$_SERVER`/`$_FILES`/`$_COOKIE`, no `eval`/`exec`/`shell_exec`/`unserialize` on untrusted data.

## Critical files

- `CLAUDE.md` — update once Phase 1a code lands.
- `wp-plugin-dev/references/architecture.md`, `security.md`, `wp-org-guidelines.md`.
- `class-service-crew-geocoding.php` — single choke point for the geocoding provider.
- `class-service-crew-payments.php` and `class-service-crew-gateway-stripe.php` — single choke point for payment providers (PayPal later).
- `class-service-crew-capacity.php` — pooled-hours date logic; pure calc, unit-tested like pricing.
- `class-service-crew-matching.php` — suggestions only; pure calc.
- `class-service-crew-emails.php`, `class-service-crew-assignments.php`, `class-service-crew-vendor-payments.php`, `class-service-crew-app-access.php`.

## Verification (per sub-phase gate)

**1a:** tables/role exist (no `sc_location`); component hierarchy, quantity settings and quantity-discount tiers round-trip; crew record round-trips type/address/ZIP/lat-lng/radius/photo; employee radius defaults to unlimited; availability incl. split shifts and time off persist; geocoding falls back ZIP → flagged and never throws; wizard resumes after closing the tab and cannot finish without a passing address test; REST rejects anonymous/low-priv writes.

**1b-1:** Stripe test-mode payment succeeds and the webhook confirms it; a bad webhook signature is rejected; duplicate webhook is idempotent; minimum deposit and tier discounts match the pricing class; partial and full refunds work and log notes; pay-page token expires and cannot be reused; secret key never appears in full in any response; emails respect on/off and edited wording.

**1b-2:** pooled capacity matches hand calculations (multi-day, crew needed > 1, time off, travel buffer); a date is disabled when its load can't fit the job; emergency option appears only on disabled dates and produces `pending_approval` with the overload flag; no surcharge text appears anywhere on the customer side; race on the last capacity shows "that date just filled" before any charge; `awaiting_payment` rows expire and don't count; duplicate email reuses one customer; failed geocode still books with the right flag.

**1c:** quote with no service lands as `requested` with description, photos, address and email; upload endpoint rejects non-images and oversize files; deposit link confirms only when paid; quote expiry and reminders fire; team assignment shows "N of M"; a decline keeps date/window and excludes that person from suggestions; vendor cannot be assigned without an agreed amount; vendor partial and full payments recorded with correct status; overtime approval and admin-only surcharge behave as specified; "unassignable" and "paid_needs_action" flags appear; no-response timer fires once; reschedule and cancel behave per the failure table.

**1d:** VAPID keypair generated once; App access on/off, invite, resend, reset and revoke work; invite email contains no password and the link is one-time and expires; revoke logs the person out everywhere; push arrives with the app closed; accept/decline/on-the-way/start/complete hit the bookings endpoint and log notes; only the lead sees status buttons on team jobs; **direct API calls confirm employees receive no money fields and vendors receive only their own agreed amount and status**; another person's job returns 403/empty; stale subscription cleanup works inline and via the daily cron.

**1e:** customer detail shows correct cross-origin history, payments and notes; a note added on the board appears immediately (same table); list filters by `source`.

**End of Phase 1:** run the `wp-plugin-review` skill on the complete codebase before calling Phase 1 done.

## Assumptions and open items

1. **Emergency "confirm":** read as the admin approving the emergency booking after the customer has paid the deposit.
2. **Advance discount base:** applies to the **whole job total**; the pay-now amount is the chosen percent of the discounted total.
3. **Date fit is per job:** a large job may see a date closed that a smaller job still sees open, because the check uses that job's per-day load.
4. **Vendor payment display:** vendors see "partially paid" with the amount paid so far, in addition to pending/paid.
5. **Reminder defaults:** quote-expiry reminder one day before; accepted-not-scheduled reminder 3 days (both admin-set).
6. **Site visits:** covered by On the way + a lead note; whether a visit needs its own status or record is open.
7. **Stripe hosted page:** the deposit and balance pay pages sit on the site and hand off to Stripe Checkout so customers can choose how much to pay now.
