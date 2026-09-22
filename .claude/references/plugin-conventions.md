# ServiceCrew plugin conventions (condensed)

Read by `plugin-lead` on every per-task pass and by `plugin-reviewer` for its cross-check step. This is the short list distilled once from `wp-plugin-dev/references/security.md`, `wp-org-guidelines.md`, and `wp-plugin-review/references/security-checklist.md`, `repo-guidelines-checklist.md` — read those full files only if something here is ambiguous, not as routine practice.

## Naming and structure
- `class-service-crew-{feature}.php` → `Service_Crew_{Feature}`. The bootstrap file holds constants, activation/deactivation hooks, the autoloader, and one init call — never feature logic.
- Directories: `includes/`, `admin/`, `public/`, `api/`, `pwa/`.
- Every custom function, class, hook, option, transient, CPT, meta key, AJAX action, and cron hook is prefixed (`sc_`/`Service_Crew_`/`_sc_`). Generic names (`get_data()`, `Settings`) are a red flag.
- Every PHP file starts with an `ABSPATH` guard (`if ( ! defined( 'ABSPATH' ) ) { exit; }`).

## Input → storage → output
- Sanitize on input: `sanitize_text_field()`, `sanitize_textarea_field()`, `sanitize_email()`, `absint()`, `wp_kses_post()` as appropriate — always through `wp_unslash()` first for `$_POST`/`$_GET`/`$_REQUEST`. Same for `$_SERVER`, `$_FILES`, `$_COOKIE`.
- Escape late, at the point of output: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`, `esc_textarea()`. Sanitizing on the way in is not a substitute for escaping on the way out.
- All custom SQL goes through `$wpdb->prepare()` — no string concatenation, no unquoted-vs-quoted `%s` mistakes. Prefer `get_option()`/`WP_Query`/`get_post_meta()` over raw SQL where a core API exists.
- File uploads: `wp_check_filetype_and_ext()`, image-only allowlist, size cap, `media_handle_upload()` — never trust `$_FILES['type']`.

## AuthZ / AuthN
- Every form has a nonce (`wp_nonce_field()` + `wp_verify_nonce()`/`check_admin_referer()`), checked *before* processing.
- Every AJAX handler calls `check_ajax_referer()`.
- Every REST route has a real `permission_callback` — never `__return_true` on a write. The one exception: the Stripe webhook, whose permission check *is* signature verification, not a capability check.
- Privileged actions check `current_user_can()` with a real capability — never `is_admin()` (that only means "on an admin page") or a hardcoded role string.

## ServiceCrew-specific non-negotiables
- **Money visibility is server-enforced.** Crew API responses are built by role-specific serializers. Employee responses contain zero money fields. Vendor responses contain only their own `agreed_amount` and payment status — never customer total, deposit, balance, discount, or surcharge. This is checked by reading the actual serializer output, not just "is there a check somewhere."
- Pricing/capacity/matching classes (`class-service-crew-pricing.php`, `class-service-crew-capacity.php`, `class-service-crew-matching.php`) are pure calculation — no `$wpdb` calls, no side effects, unit-testable in isolation.
- Payment tokens (pay-page links) are stored hashed, expire, and are single-purpose.
- No customer-facing "we don't service this area", no surcharge/extra-price text shown to the customer, no status page / door code / self-service cancel-reschedule.
- Declines keep date and window; only the person is cleared. Capacity counts a booking whether or not it has an assignee.

## Repo/WP.org hygiene
- No deprecated WP or PHP functions. No `eval`/`exec`/`shell_exec`/`system`/`unserialize()` on untrusted data.
- WP-Cron only, never system cron.
- No inline JS/CSS in PHP; Bootstrap build-time only (bundled via the npm/Sass pipeline and enqueued locally), no CDN JS/CSS.
- No bundled copies of WP-shipped libraries (jQuery, etc.).
- External requests use `wp_remote_get()`/`wp_remote_post()` with a timeout — never raw cURL or `file_get_contents()`.
- Any external service call (Nominatim, Stripe, Web Push relay) needs a matching `readme.txt` disclosure — flag it if the code changed but the disclosure didn't.

## Red flags that mean an automatic FLAG from plugin-lead
- Raw superglobal used without sanitization, anywhere.
- `echo`/`printf` of a variable without an `esc_*()` call.
- A REST write route with `__return_true` or no `permission_callback` at all.
- Any query building SQL by concatenating a variable.
- A crew API response (employee or vendor) containing a money field it isn't supposed to.
- A schema or status/flag change to `sc_bookings` with no corresponding update to the code that reads it (dispatch board, emails, capacity).
