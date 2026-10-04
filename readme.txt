=== ServiceCrew ===
Contributors: (your wordpress.org username)
Tags: booking, scheduling, dispatch, field-service, stripe
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.0.38
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Door-to-door service booking, dispatch, payments, and a chat sales agent for field-service businesses.

== Description ==

ServiceCrew turns a WordPress site into a booking and dispatch system for
door-to-door service businesses (cleaning, repair, lawn care, and similar):
customers book instantly or request a quote, pay a deposit via Stripe, and
admins assign crew, track job status, and collect balances — all from one
admin screen.

It also includes an optional rule-based chat agent (no AI/LLM, no paid API)
that can answer visitor questions from your services and a knowledge base,
walk a visitor through requesting a quote, booking a service, or paying an
existing deposit, and flag anything it can't answer for a human to follow up
on — optionally with a browser push notification.

**Key features**

* Instant booking with live pricing, add-ons, and Stripe Checkout deposits
* Quote requests with photo attachments, admin pricing, and deposit links
* Crew assignment, job-status updates, and overtime/emergency handling
* Customer list with booking history and marketing opt-in
* A rule-based chat agent: FAQ matching, quote/booking/payment chat flows,
  and an admin inbox for anything it couldn't confidently answer

**External services this plugin connects to**

* **Stripe** (stripe.com) — processes deposit and balance payments via
  Stripe Checkout. No card data touches this site. See Stripe's
  [Privacy Policy](https://stripe.com/privacy) and
  [Terms of Service](https://stripe.com/legal).
* **OpenStreetMap Nominatim** (nominatim.org) — converts a customer's
  entered address into coordinates for crew-matching, and verifies an
  entered ZIP/postal code against the address. See Nominatim's
  [Usage Policy](https://operations.osmfoundation.org/policies/nominatim/).
* **Your browser's own push service** (e.g. Google FCM for Chrome, Mozilla
  Autopush for Firefox) — only if an admin explicitly enables chat
  notifications from the Agent settings screen. No message content is ever
  sent through this service; it only wakes the admin's browser to show a
  generic "check the Agent screen" notification.

All three are disclosed to the site admin during first-run setup and/or on
the relevant settings screen, and only activate once configured.

== Installation ==

1. Upload and activate the plugin.
2. Follow the setup wizard (Business basics → Scheduling → Address lookup
   consent → Payments → First service → Agent → First crew member).
3. Add `[service_crew_services]` and/or `[service_crew_booking]` to a page,
   or enable the chat agent instead from the Agent settings screen.

== Frequently Asked Questions ==

= Does this plugin work without Stripe? =

Instant booking requires Stripe. The quote-request form and the chat
agent's FAQ matching both work without it.

= Does the chat agent use AI? =

No. It matches visitor questions against your services and a
manually-curated (plus self-learned) knowledge base using keyword overlap,
not a language model — there's no AI/API cost or external data sharing
beyond what's listed above.

= What happens to my data if I delete the plugin? =

Uninstalling (not just deactivating) permanently removes every table,
option, service/crew post, and the crew-member role this plugin created.
Deactivating alone keeps everything in place.

== Changelog ==

= 1.0.38 =
* Booking, quotes, dispatch, payments, and customer CRM.
* Rule-based chat sales agent: FAQ/knowledge-base matching, FAQ content
  read from site pages/posts, quote/booking/payment chat flows, an admin
  escalation inbox, optional push notifications, and setup-wizard
  integration.
* `uninstall.php` added — plugin data no longer persists after a full
  uninstall.
