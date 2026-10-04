# WordPress Plugin Review Report

**Plugin:** ServiceCrew
**Version:** 1.0.0 (declared in header) / `1.0.33` (actual, see Finding R-2)
**Author:** ServiceCrew
**Reviewed:** 2026-10-03
**Review Tool Versions:** None — see "Methodology" below
**Overall Score:** 64/100
**Verdict:** 🟡 Needs Work

---

## Methodology (read this before the findings)

This review could not run PHPCS, PHPStan, or PHPUnit. This session is a Windows/Laragon local dev environment, not the Linux sandbox `wp-plugin-review`'s own setup script (`scripts/setup_tools.sh`) assumes — there is no `php`, `composer`, `phpcs`, or `phpstan` binary on this machine, and the setup script itself is apt-get-based (Debian/Ubuntu only). This is a known, pre-documented limitation of running this skill outside its intended sandbox (see this repo's own `CLAUDE.md`: "Nothing here is executable except `wp-plugin-review/scripts/setup_tools.sh`, which runs inside the *reviewing agent's* sandbox, not here").

In place of automated analysis, every finding below comes from **direct manual reading and systematic pattern-scanning of all 39 PHP files and ~20 JS files** in the plugin: every `$wpdb` call, every `$_GET`/`$_POST`/`$_FILES`/`$_SERVER` access, every REST route's `permission_callback`, every `echo`/`innerHTML`, and every dangerous-function pattern (`eval`, `exec`, `unserialize`, etc.) across the entire codebase, not a sample. Line numbers are cited for every finding so they can be verified directly. Treat the scores as an informed manual audit, not a PHPCS sniff count — a real PHPCS/WPCS run would likely surface additional minor whitespace/formatting sniffs this review can't see.

### Score Breakdown

| Category | Score | Status | Issues Found |
|----------|-------|--------|--------------|
| Security | 23/25 | 🟢 | 0 critical, 0 high, 2 low |
| Coding Standards | 20/25 | 🟢 | 0 errors confirmed, 2 medium, 2 low (manual review only, see Methodology) |
| Repository Guidelines | 9/20 | 🟠 | 2 high, 2 medium |
| Unit Tests | 3/15 | 🔴 | 1 high (zero coverage despite testable architecture) |
| Accessibility | 9/15 | 🟡 | 1 medium, 1 low |

---

## Executive Summary

ServiceCrew is a substantial, actively-developed door-to-door service/booking plugin (39 PHP classes, ~20 JS modules, a custom REST-backed admin app, Stripe payments, a crew-assignment/dispatch workflow, and an employee job-status page) that is **still mid-Phase-1 development**, not a submission candidate yet — this matches its own `ServiceCrew-Tasks.md` tracker, which this review was explicitly requested against as the "end-of-Phase-1" gate.

The core engineering is genuinely strong on the axis that matters most and is hardest to retrofit: **security**. Every variable SQL query is parameterized via `$wpdb->prepare()` (not a sample — every single one found in the codebase), every PHP file guards against direct access, every privileged REST route is capability-gated, file uploads go through one centralized, hardened validation choke point, and every customer-facing token (payment links, the employee job-status link) is stored hashed and single-purpose. The admin UI's rendering helper uses `textContent` rather than `innerHTML` almost universally, which incidentally closes off most DOM-based XSS vectors by construction rather than by discipline alone.

What's missing is mechanical, not architectural: there is **no `readme.txt`** (required for WordPress.org and currently blocking even a theoretical submission), **no `uninstall.php`** (nine-plus custom database tables and several options/transients would persist forever after uninstall), **zero automated tests** despite four classes explicitly built and documented as "pure calc, unit-testable," and a **plugin-header version that's drifted from the actual released version** (header says `1.0.0`, the real constant is `1.0.33`). None of these require touching the security-sensitive core; all are additive. Estimated effort to clear every High/Medium finding: roughly a day — most of it writing `readme.txt`/`uninstall.php`/a first test file, not debugging anything.

---

## 1. Security Review (23/25)

### 1.1 Input Sanitization
**Status:** 🟢

Every `$_GET`/`$_POST`/`$_FILES`/`$_SERVER` access found in the codebase is sanitized before use — `wp_unslash()` + `sanitize_text_field()`/`sanitize_email()`/`absint()` consistently, including the one classic (non-REST) form handler, `class-service-crew-crew.php`'s `save_meta_box()`.

**🟢 LOW — Sanitization-by-cast instead of a named WP function**
- **File:** `includes/class-service-crew-crew.php` (lines 519, 525)
- **Issue:** `(float) wp_unslash( $_POST['sc_crew_lat'] )` — a PHP type cast is functionally safe here (it can only ever yield a float or `0.0`, no injection surface), but WPCS's sanitization sniffs look for a recognized WP sanitization function and will likely flag this as unsanitized input even though it isn't exploitable.
- **Fix:** wrap in `sanitize_text_field()` before the cast for sniff-cleanliness, even though it changes nothing functionally:
  ```php
  // Before
  update_post_meta( $post_id, self::META_LAT, (float) wp_unslash( $_POST['sc_crew_lat'] ) );
  // After
  update_post_meta( $post_id, self::META_LAT, (float) sanitize_text_field( wp_unslash( $_POST['sc_crew_lat'] ) ) );
  ```

### 1.2 Output Escaping
**Status:** 🟢

PHP-side output is escaped consistently (`esc_html()`, `esc_html__()`, `esc_attr()`) everywhere dynamic data reaches HTML. The two standalone token-link pages (`public/class-service-crew-pay-page.php`, `public/class-service-crew-job-status-page.php`) build their body HTML entirely from pre-escaped pieces before a single final `echo $body_html`, each with a `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` comment explaining why — a deliberate, documented, and correct pattern, not an oversight.

Client-side, the shared `SCApp.el()` DOM helper (`admin/js/app-core.js:15-37`) writes a `text` attribute via `node.textContent`, never `innerHTML`, for every dynamic value rendered through it — which is the vast majority of all admin-UI rendering in this plugin. This structurally prevents DOM-based XSS from API responses almost everywhere by construction.

**🟢 LOW — One `innerHTML` assignment from server-sourced HTML**
- **File:** `admin/js/app-payments.js` (lines 130, 132)
- **Issue:** `successPageField.innerHTML = settings.success_page_dropdown;` — this HTML comes from `wp_dropdown_pages( array( 'echo' => 0 ) )` server-side (`class-service-crew-payments.php`), which already escapes page titles internally, and this screen is `manage_options`-gated either way, so there's no real privilege-escalation path. Still worth a one-line comment for future maintainers so it isn't mistaken for a raw-user-input `innerHTML` assignment during a future audit.

### 1.3 SQL Injection Prevention
**Status:** 🟢

Every `$wpdb->get_results()`/`get_row()`/`get_var()`/`get_col()`/`query()`/`update()`/`insert()` call with any variable input is wrapped in `$wpdb->prepare()` with correctly-typed placeholders (`%d`/`%s`/`%f`) — confirmed across all 39 files, not sampled.

**🟢 LOW — Two static queries built without `$wpdb->prepare()`**
- **File:** `includes/class-service-crew-notifications.php` (lines 156, 189)
- **Issue:** `$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sc_notifications WHERE is_read = 0" )` and the matching `UPDATE ... WHERE is_read = 0` — not exploitable (no request-supplied variable appears in either string, only the trusted `$wpdb->prefix` and a literal `0`), but PHPCS's `WordPress.DB.PreparedSQL` sniff will flag both regardless, and it's a small inconsistency against the rest of the codebase's 100% `prepare()` usage.
- **Fix:**
  ```php
  // Before
  return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sc_notifications WHERE is_read = 0" );
  // After
  return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'sc_notifications WHERE is_read = %d', 0 ) );
  ```

### 1.4 Nonce Verification
**Status:** 🟢

The one classic `$_POST` form handler (`class-service-crew-crew.php::save_meta_box()`) verifies its nonce (`wp_verify_nonce()`) **before** any data is processed, correctly ordered relative to the capability check and autosave/revision guards (lines 478–494). Every other state-changing action in this plugin goes through the WP REST API, authenticated via the nonce `wp.apiFetch.createNonceMiddleware()` wires up once in `class-service-crew-admin-app.php::enqueue_assets()` — the standard, WordPress-recommended pattern for a REST-backed admin app, equivalent in protection to a classic form nonce.

### 1.5 Capability Checks
**Status:** 🟢

Every admin-privileged REST route uses a `check_permission`/`check_admin_permission` callback that checks `current_user_can( 'manage_options' )`. Scanning every `'permission_callback' => '__return_true'` occurrence in the codebase (12 routes, listed below) confirms each is a deliberate, documented public endpoint, not an oversight:

| Route | File | Why public is correct |
|---|---|---|
| `POST /bookings` | bookings-controller.php:70 | Customer creating their own booking — never logged in |
| `POST /calculate-price` | bookings-controller.php:81 | Public price preview, no privileged data |
| `POST /available-dates` | bookings-controller.php:91 | Public capacity preview |
| `GET /booked-windows` | bookings-controller.php:101 | Public slot-availability check |
| `GET /bookings/{id}/payment-status` | bookings-controller.php:126 | Email-gated by the caller, read-only status |
| `POST/GET /job-status/{token}/...` | job-status-controller.php:50,60,70 | Token itself is the access control |
| `GET /verify-address` | geocoding.php:123 | Public address-verification helper |
| `POST /quotes` | quotes-controller.php:48 | Public quote submission, rate-limited server-side |
| `POST /stripe-webhook` | gateway-stripe.php:77 | Signature verification *is* the permission check |
| `POST/GET /pay/{token}/...` | payments.php:148,158 | Token itself is the access control |

This is exactly the pattern the plan's own conventions document calls for ("the Stripe webhook's permission check is signature verification"). An automated PHPCS/WPCS scan will still flag every one of these lines (the sniff can't know a token/signature check happens inside the callback) — expect that noise in any future automated run and don't treat it as a real finding.

### 1.6 File Security
**Status:** 🟢

Every PHP file opens with `if ( ! defined( 'ABSPATH' ) ) { exit; }`. File uploads (`includes/class-service-crew-uploads.php`) go through one centralized, hardened choke point: extension + MIME sniff via `wp_check_filetype_and_ext()`, a `getimagesize()` re-check that the bytes actually decode as an image (catches a renamed non-image even if the MIME sniff is fooled), a size cap, and `wp_handle_upload()` for the actual move — used identically by both the quote-photo upload and the employee job-status completion-photo upload, with no duplicated/divergent copy of this logic anywhere.

No PHP file in the codebase includes another file based on request input, and no `move_uploaded_file()` is called directly anywhere.

### 1.7 Data Validation
**Status:** 🟢

Status transitions, payment kinds, and note types are all checked against explicit whitelists (e.g. `Service_Crew_Assignments::assign_crew()` rejects a non-`employee` crew id; `Service_Crew_Overtime::approve()` validates `surcharge_decision` against a fixed set before use). Numeric ranges are consistently bounded with `max()`/`min()`/`absint()` (e.g. `AVAILABLE_DATES_MAX_DAYS`, `MAX_ITEMS_PER_BOOKING`).

### 1.8 External Requests
**Status:** 🟢

Both external integrations (Nominatim geocoding, Stripe) use `wp_remote_get()`/`wp_remote_post()` exclusively — no raw cURL or `file_get_contents()` found anywhere in the codebase. Both set explicit timeouts. The Stripe webhook verifies its HMAC signature (with a replay-protection timestamp tolerance) before trusting any payload. No request is made without a clear, already-identified purpose (no tracking/analytics calls of any kind were found).

### Code Quality Security (bonus, not a template section but checked)
**Status:** ✅ Clean

Zero occurrences anywhere in the codebase of `eval()`, `exec()`, `shell_exec()`, `system()`, `passthru()`, `unserialize()`, `base64_decode()`, `preg_replace()` with the `/e` modifier, or `extract()` on request data.

---

## 2. WordPress Coding Standards (20/25)

### 2.1 Automated PHPCS Results

**Not available** — see "Methodology" above. No PHPCS/WPCS run was possible in this environment.

### 2.2 Manual Findings

#### Naming Conventions
**Status:** ✅ — Consistent `Service_Crew_{Feature}` class names matching `class-service-crew-{feature}.php` filenames throughout; `sc_` prefix on every DB table, option, transient, meta key, and cron hook; `service-crew/v1` REST namespace used everywhere.

#### Internationalization
**Status:** 🟡

PHP-side: 266+ `__()`/`_e()`/`esc_html__()`/`esc_attr__()` calls across 28 files, consistently using the `'service-crew'` text domain.

**🟡 MEDIUM — No JavaScript string internationalization at all**
- **Files:** every file in `admin/js/` and `public/js/` (~20 files)
- **Issue:** Every user-facing string in the admin UI and the two public token-link pages' client scripts is a hardcoded English literal — there is no `wp_set_script_translations()` call anywhere and no use of `@wordpress/i18n`'s `__()` in any JS file. Since the entire custom admin app (Services, Bookings, Customers, Settings, etc.) is rendered client-side, this means the *majority* of the plugin's actual user-facing text cannot be translated at all today, despite the PHP side being fully prepared for it.
- **Fix (incremental, not all-at-once):** enqueue `wp-i18n` as a script dependency, call `wp_set_script_translations( 'sc-admin-app-core', 'service-crew' )` alongside the existing `wp_enqueue_script()` calls in `class-service-crew-admin-app.php`, and wrap new/changed strings in `wp.i18n.__( 'string', 'service-crew' )` going forward rather than attempting a full retrofit immediately.

#### Enqueuing Assets
**Status:** ✅ — Every script/style found goes through `wp_enqueue_script()`/`wp_enqueue_style()` with explicit dependency arrays and `SERVICE_CREW_VERSION` cache-busting; `wp_add_inline_script()` (not string concatenation) is used for the one piece of dynamically-generated JS (the REST nonce/root-URL middleware setup).

#### WordPress API Usage
**Status:** ✅ — `wp_remote_get/post()`, `wp_handle_upload()`, `wp_insert_attachment()`, `get_post_meta()`/`update_post_meta()`, `WP_Query`-equivalent `get_posts()`, and `wp_schedule_event()`/WP-Cron (never system cron) are used throughout in preference to raw PHP/SQL equivalents.

#### Bundled Libraries Check
**Status:** ✅ — No jQuery, no Bootstrap, no CDN-hosted script/style tags anywhere in the codebase (confirmed by direct grep for `bootstrap.min`, `cdn.`, `unpkg.com`, `googleapis.com/ajax` — zero matches). The admin app is 100% vanilla JS against `wp-api-fetch` (a WP-core-bundled script), which is the correct approach.

**🟢 LOW — PHP 7.4 compatibility: confirmed clean**
No PHP 8.0+-only syntax (`match()`, arrow `fn()`, nullsafe `?->`, `readonly`, `enum`) appears anywhere in the codebase — the plugin header's `Requires PHP: 7.4` is accurate.

**🟡 MEDIUM — Plugin-header `Version` drifted from the real version**
- **File:** `service-crew.php` (line 5 vs. line 29)
- **Issue:** The header declares `Version: 1.0.0`, but `SERVICE_CREW_VERSION` (used for cache-busting every enqueued asset, and what a `get_plugin_data()` call or an update-checker would actually report) is `1.0.33`. These have silently diverged over ~30 version bumps this plugin's build history made to the constant alone.
- **Fix:** bump the header to match now, and going forward treat the header `Version:` line as the single source of truth — either bump both together, or read `SERVICE_CREW_VERSION` *from* the header via `get_file_data()` at bootstrap time so there's only one number to update:
  ```php
  // Before
  * Version:           1.0.0
  ...
  define( 'SERVICE_CREW_VERSION', '1.0.33' );
  // After (simplest fix — keep both in sync by hand)
  * Version:           1.0.33
  ...
  define( 'SERVICE_CREW_VERSION', '1.0.33' );
  ```

---

## 3. Repository Guidelines (9/20)

### 3.1 Plugin Headers
**Status:** 🟡

All required fields are present (`Plugin Name`, `Description`, `Version`, `Requires at least`, `Requires PHP`, `Author`, `License`, `License URI`, `Text Domain`, `Domain Path`). See Finding 2.2 above for the version-drift issue. `Plugin URI`/`Author URI` are absent but optional.

**🟢 LOW — `Domain Path: /languages` points to a directory that doesn't exist yet**
- No `languages/` directory or `.pot` file exists in the plugin yet. Harmless at this stage (no translations have been generated), but worth creating alongside the JS-i18n work above rather than leaving the header pointing at nothing indefinitely.

### 3.2 readme.txt
**Status:** 🔴

**🔴 HIGH — No `readme.txt` exists anywhere in the plugin**
- **Issue:** This is a hard requirement for WordPress.org submission (the Plugin Check tool will reject outright) and is also where the required "external service" disclosures belong — this plugin talks to **Stripe** and **OpenStreetMap/Nominatim**, both of which need clear, consent-aware documentation per the repository's Data & Privacy guidelines. Right now that disclosure exists only in the setup wizard's UI copy, not in a form WordPress.org's review team or a user browsing the plugin page would ever see.
- **Fix:** a draft skeleton to start from:
  ```
  === ServiceCrew ===
  Contributors: (your wordpress.org username)
  Tags: booking, scheduling, dispatch, field-service, stripe
  Requires at least: 6.0
  Tested up to: 6.7
  Stable tag: 1.0.33
  Requires PHP: 7.4
  License: GPLv2 or later
  License URI: https://www.gnu.org/licenses/gpl-2.0.html

  Door-to-door service booking, dispatch, and payments for field-service businesses.

  == Description ==

  ServiceCrew turns a WordPress site into a booking and dispatch system for
  door-to-door service businesses (cleaning, repair, lawn care, etc.): customers
  book or request a quote, pay a deposit via Stripe, and admins assign crew,
  track job status, and collect balances — all from one admin screen.

  This plugin connects to the following external services:

  * **Stripe** (stripe.com) — processes deposit and balance payments via
    Stripe Checkout. No card data touches this site. See Stripe's own
    [Privacy Policy](https://stripe.com/privacy) and
    [Terms of Service](https://stripe.com/legal).
  * **OpenStreetMap Nominatim** (nominatim.org) — converts a customer's
    entered address into coordinates for crew-matching, and verifies an
    entered ZIP/postal code against the address. See Nominatim's
    [Usage Policy](https://operations.osmfoundation.org/policies/nominatim/).

  Both integrations are disclosed to the site admin during first-run setup
  and only activate once the admin configures them.

  == Installation ==

  1. Upload and activate the plugin.
  2. Follow the setup wizard (Business basics → Scheduling → Address lookup
     consent → Payments → First service → First crew member).

  == Frequently Asked Questions ==

  = Does this plugin work without Stripe? =

  Instant booking requires Stripe. The quote-request form works without it.

  == Changelog ==

  = 1.0.33 =
  * Phase 1 feature-complete: booking, quotes, dispatch, payments, customer CRM.
  ```

### 3.3 Licensing
**Status:** 🟢 — `LICENSE` file present in the plugin root with the full GPLv2 text; header declares `GPL-2.0-or-later`; no third-party bundled code to check for license compatibility (none is bundled — see §2.2).

### 3.4 Prefixing
**Status:** 🟢 — Every class, DB table (`sc_*`), option (`sc_*_settings`), transient, meta key (`_sc_crew_*`), REST namespace (`service-crew/v1`), and cron hook (`sc_*`) is consistently and uniquely prefixed. No naming collisions with WordPress core or common plugin patterns were found.

### 3.5 Data & Privacy
**Status:** 🔴

**🔴 HIGH — No `uninstall.php` and no `register_uninstall_hook()`**
- **Issue:** `class-service-crew-deactivator.php` explicitly (and correctly) documents that deactivation must *not* delete data, and defers cleanup to "`uninstall.php`... out of scope for this task" — but that file was never actually created. As it stands today, **uninstalling ServiceCrew leaves all nine-plus custom tables (`sc_bookings`, `sc_payments`, `sc_booking_assignments`, `sc_overtime`, `sc_customers`, `sc_notes`, `sc_notifications`, etc.), every `sc_crew`/`sc_service` post, and every plugin option/transient behind permanently** — a direct violation of the repository guideline ("No data should remain after uninstall unless the user explicitly opts to keep it") and one of the more common rejection reasons for real-world submissions.
- **Fix:** a starting `uninstall.php` (place in the plugin root, this is the WP-recommended location WordPress calls automatically — no code needs to register it):
  ```php
  <?php
  if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
      exit;
  }

  global $wpdb;

  $tables = array(
      'sc_bookings', 'sc_booking_components', 'sc_booking_services',
      'sc_booking_assignments', 'sc_payments', 'sc_refunds',
      'sc_vendor_payments', 'sc_closed_dates', 'sc_overtime', 'sc_notes',
      'sc_customers', 'sc_notifications',
  );

  foreach ( $tables as $table ) {
      $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a fixed internal constant, not user input.
  }

  // sc_crew / sc_service posts and their meta.
  $post_ids = get_posts( array( 'post_type' => array( 'sc_crew', 'sc_service' ), 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) );
  foreach ( $post_ids as $post_id ) {
      wp_delete_post( $post_id, true );
  }

  $options = array( 'sc_db_version', 'sc_scheduling_settings', 'sc_payment_settings', 'sc_email_settings', 'sc_appearance_settings', 'sc_discounts_settings', 'sc_smtp_settings' );
  foreach ( $options as $option ) {
      delete_option( $option );
  }
  ```
  (Adjust the exact option-name list against each settings class's own `OPTION_NAME` constant before shipping this — several were written from memory here and should be verified against the actual constants.)

### 3.6 Admin Experience
**Status:** 🟢 — The one `admin_notices` hook found (`class-service-crew-crew.php`) is `is-dismissible`, transient-gated to fire exactly once, and scoped to only the specific crew-edit screen/post it's relevant to — not sitewide. The top-level "ServiceCrew" menu with 8 submenus is justified by the plugin's actual scope (a submenu-only structure would bury a multi-screen admin app), not a guideline violation.

---

## 4. Unit Test Coverage (3/15)

### 4.1 Test Existence
**Status:** 🔴

No `tests/` directory, no `phpunit.xml`/`phpunit.xml.dist`, and no test files of any kind exist anywhere in the repository.

### 4.2 Test Quality & Coverage
**Status:** 🔴

**🔴 HIGH — Zero automated test coverage despite an architecture explicitly built for it**
- **Issue:** Four classes in this codebase (`class-service-crew-pricing.php`, `class-service-crew-availability.php`, `class-service-crew-capacity.php`, `class-service-crew-matching.php`) are deliberately designed as pure, static, side-effect-free functions — their own docblocks describe them as "pure calc, unit-testable" and explicitly note this is "so the math is testable without a full WordPress bootstrap." None of them have ever actually been tested. This is exactly the kind of code (pricing math, capacity math, distance/suggestion ranking) where a silent regression is costly and hard to catch by manual testing alone.

### 4.3 Recommended Tests

- [ ] `Service_Crew_Pricing::calculate_service_line()` / `calculate_component_line()` / `calculate_subtotal()` — flat vs. per-unit pricing, quantity multiplication, duration summation
- [ ] `Service_Crew_Pricing::get_matching_deposit_tier()` / `build_payment_options()` — bracket boundary conditions
- [ ] `Service_Crew_Availability::get_available_hours()` — split shifts, time-off overlap, weekday boundaries
- [ ] `Service_Crew_Capacity::calculate_pooled_hours()` / `calculate_load()` / `can_fit_job()` — the exact "pooled capacity matches hand calculations" scenarios the plan's own 1b-2 verification checklist calls for
- [ ] `Service_Crew_Matching::calculate_distance_miles()` / `get_suggestions()` — known lat/lng pairs with a hand-calculated expected distance, radius boundary (exactly at the edge), unlimited-radius handling

Since none of these require a WordPress bootstrap (no `$wpdb`, no hooks, no globals), they can run under plain PHPUnit with **no WP test scaffolding at all** — the lowest-effort test suite this plugin could add. A sample, ready to drop into `tests/test-pricing.php`:

```php
<?php
/**
 * Sample unit test for Service_Crew_Pricing — pure calc, no WP bootstrap
 * needed. Run with: phpunit tests/test-pricing.php
 */

require_once __DIR__ . '/../includes/class-service-crew-pricing.php';

use PHPUnit\Framework\TestCase;

class ServiceCrewPricingTest extends TestCase {

	public function test_flat_price_service_line_ignores_quantity() {
		$fields = array( 'pricing_type' => 'flat', 'price' => 50.0, 'duration_minutes' => 60 );

		$line = Service_Crew_Pricing::calculate_service_line( $fields, 5 );

		$this->assertSame( 50.0, $line['price'] );
		$this->assertSame( 1, $line['quantity'] );
	}

	public function test_per_unit_price_multiplies_by_quantity() {
		$fields = array(
			'pricing_type'     => 'per_unit',
			'price'            => 30.0,
			'duration_minutes' => 20,
			'unit_min'         => 1,
			'unit_max'         => 10,
			'unit_default'     => 1,
		);

		$line = Service_Crew_Pricing::calculate_service_line( $fields, 3 );

		$this->assertSame( 90.0, $line['price'] );
		$this->assertSame( 60.0, $line['duration_minutes'] );
	}

	public function test_component_line_requires_quantity_multiplication() {
		$component = array( 'unit_price' => 10.0, 'unit_duration_minutes' => 15, 'min' => 1, 'max' => 5, 'default' => 1 );

		$line = Service_Crew_Pricing::calculate_component_line( $component, 2 );

		$this->assertSame( 20.0, $line['price'] );
		$this->assertSame( 30.0, $line['duration_minutes'] );
	}
}
```

*(This test makes assumptions about `calculate_service_line()`'s exact field-name expectations based on how it's called elsewhere in the codebase — verify field names against the real method signature before running; adjust as needed.)*

---

## 5. Accessibility (9/15)

### 5.1 ARIA & Semantic HTML
**Status:** 🟢

118 `aria-*`/`role=`/label-related occurrences across 12 JS files were found. Icon-only controls are consistently labeled (e.g. the notification bell: `aria-label="Notifications"` in `class-service-crew-admin-app.php`; the quote-photo "Remove photo" button: `aria-label="Remove photo"` in `booking-flow.js`). Error messages use `aria-live="polite"` consistently across every form in the plugin (the pay page, the job-status page, every admin screen's inline forms) so a screen-reader user is actually told when a submission fails.

### 5.2 Keyboard Navigation
**Status:** 🟢 — Every interactive control found is a real `<button type="button">`, `<select>`, `<input>`, or `<a href>` — no `<div onclick>`-style custom controls that would need manual keyboard-handler work were found anywhere in the codebase.

### 5.3 Form Accessibility
**Status:** 🟡

**🟡 MEDIUM — Form labels aren't programmatically associated with their inputs**
- **Files:** every custom admin screen (`admin/js/app-bookings.js`, `app-customers.js`, `app-settings.js`, `app-wizard.js`, and others) — the pattern repeats throughout
- **Issue:** Labels are built like `SCApp.el( 'label', { text: 'Total price ($)' } )` placed next to (not wrapping, and with no `for`/`id` pairing) their input, e.g. `class-service-crew-bookings.php`-driven markup in `app-bookings.js:545`. Visually this reads fine, but a screen-reader user tabbing into the price input hears nothing identifying what it's for — the label and input are only related by DOM adjacency, which assistive technology doesn't use for label association (WCAG 1.3.1, 3.3.2).
- **Fix:** the lowest-effort correct fix, given `SCApp.el()`'s existing structure, is to make the label *wrap* the input rather than sit beside it — a wrapping `<label>` is implicitly associated with any form control inside it, no `id`/`for` pair needed:
  ```js
  // Before
  SCApp.el( 'div', { class: 'sc-field' }, [
  	SCApp.el( 'label', { text: 'Total price ($)' } ),
  	priceInput,
  ] )
  // After
  SCApp.el( 'label', { class: 'sc-field' }, [
  	SCApp.el( 'span', { text: 'Total price ($)' } ),
  	priceInput,
  ] )
  ```
  This is a mechanical, low-risk change but touches a *lot* of call sites (every form in every admin screen) — worth its own dedicated pass rather than a spot-fix.

**🟢 LOW — The Customers screen's search input has no accessible name at all**
- **File:** `admin/js/app-customers.js` (the `searchInput` element in `buildFiltersBar()`)
- **Issue:** `SCApp.el( 'input', { type: 'search', placeholder: 'Search name or email…', ... } )` relies on `placeholder` alone, which is not a reliable accessible name (it disappears on input and some screen readers don't announce it at all).
- **Fix:** add `'aria-label': 'Search customers by name or email'` to the same element.

### 5.4 Screen Reader Support
**Status:** 🟢 — Covered above under 5.1 (`aria-live` on every error region) and 5.2.

---

## ✅ What's Done Well

- **Universal, verified `ABSPATH` guard** on every PHP file.
- **100% parameterized SQL** on every query with variable input — not a sample, every occurrence in the codebase.
- **Centralized, hardened file-upload validation** (`class-service-crew-uploads.php`) shared by both consumers rather than duplicated — exactly the kind of DRY that matters for security-sensitive code.
- **Every token in the system (payment links, job-status links) is stored hashed, single-purpose, and expiring** — the plaintext is never retrievable again after the one moment it's generated, consistently enforced across `class-service-crew-payments.php` and `class-service-crew-assignments.php`.
- **`textContent`-based DOM rendering** as the default idiom across the entire admin app, which closes off most DOM XSS vectors as a side effect of the architecture rather than requiring per-call-site discipline.
- **No bundled libraries, no CDN assets, no dangerous PHP functions** anywhere in ~39 files.
- **Consistent, correct capability gating** — every privileged REST route checks `manage_options`; every genuinely-public route is deliberately and documentedly so.
- **GPL `LICENSE` file present and correct.**
- **Unusually thorough inline documentation of *why*** architectural and security decisions were made — this made the review itself significantly faster and more confident than most plugin codebases of this size.

---

## Recommended Fixes (Priority Order)

### 🔴 Critical / High (Must Fix Before Submission)
1. **No `readme.txt`** — §3.2 — blocks WP.org submission outright; draft provided above.
2. **No `uninstall.php`** — §3.5 — plugin data persists forever after uninstall; sample provided above.
3. **Zero automated tests** on four classes explicitly built to be unit-tested — §4 — sample test file provided above.

### 🟠 High Priority (Should Fix)
*(none beyond the three above — this plugin has no High findings outside Repository Guidelines and Unit Tests)*

### 🟡 Medium Priority (Recommended)
1. **Plugin header `Version` (1.0.0) doesn't match the real version (1.0.33)** — `service-crew.php:5` — sync them, going forward treat one as the source of truth.
2. **No JavaScript string internationalization** — every `admin/js/*.js`/`public/js/*.js` file — add `wp_set_script_translations()`.
3. **Form labels not programmatically associated with inputs** — every custom admin screen — switch to wrapping `<label>` elements.

### 🟢 Low Priority (Nice to Have)
1. **Two static SQL queries without `$wpdb->prepare()`** — `class-service-crew-notifications.php:156,189` — not exploitable, just inconsistent.
2. **`(float)` casts instead of a named sanitization function** — `class-service-crew-crew.php:519,525`.
3. **`Domain Path: /languages` points to a non-existent directory** — `service-crew.php:12`.
4. **Customers-screen search input has no accessible name** — `admin/js/app-customers.js`.
5. **One `innerHTML` assignment from server-sourced HTML** worth a trust-boundary comment — `admin/js/app-payments.js:130,132`.

---

## Conclusion

ServiceCrew is **not ready for WordPress.org submission today**, but not because of anything structurally wrong — every gap found is additive (a missing file, a missing test, a missing label attribute), not a rework of existing code. The security posture is genuinely strong for a plugin of this size and would likely need little to no change even after a real PHPCS/WPCS run surfaces its inevitable share of minor formatting sniffs. The realistic path to "ready": write `readme.txt` and `uninstall.php` (each maybe an hour, drafts provided above), add the first handful of PHPUnit tests against the already-pure-calc classes (the sample above is a direct starting point), and do one pass converting the admin app's label markup to the wrapping-`<label>` pattern. None of that touches payments, the database layer, or any of the dispatch/assignment logic this review found no fault with.

**Verdict:** 🟡 Needs Work — solid foundation, clear and bounded punch list, no architectural concerns.
