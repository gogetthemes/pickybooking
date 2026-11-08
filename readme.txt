=== PickyBooking ===
Contributors: gogetthemes
Donate link: https://pickybooking.com/
Tags: booking, reservation, floor plan, table booking, appointment
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.18
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free visual floor-plan booking for WordPress. Design bookable layouts, embed with a shortcode, and manage reservations by email.

== Description ==

**PickyBooking** is the free WordPress.org edition of PickyBooking. It gives you everything needed for a working visual booking flow:

* Drag-and-drop layout builder with **bookable items** (square and circle)
* Guest booking UI via the `[pickybooking id="LAYOUT_ID"]` shortcode
* Booking modes: Classic, Smart, Smart Map, and Simple
* Work days, time slots, form fields, and layout colors
* Admin calendar + bookings list
* Email notifications (WordPress `wp_mail`)
* Bundled layout presets (restaurant, cinema, coworking, and more)

This plugin is a **complete free product**. Premium features are **not** included as locked/trial code (WordPress.org guideline). They ship separately in the commercial **PickyBooking Pro** plugin.

= What is free =

* Visual builder (bookable items)
* Layouts and bookings (subject to your hosting)
* Shortcode embed
* Email notifications
* Layout library presets

= PickyBooking Pro (separate product) =

* Stripe / WooCommerce deposits and payments
* Decorative builder layers (walls, text, shapes, icons)
* SMS (Vonage), booked guest avatars, and layout drafting tools
* HubSpot, Mailchimp, Zapier, Make, n8n, Google Calendar
* Layout JSON import/export, Elementor widget
* White-label admin branding

Buy Pro: [pickybooking.com](https://pickybooking.com/#pricing)

= Privacy =

Booking data is stored in your WordPress database. Emails use WordPress `wp_mail`.

Payments, CRM, calendar, and SMS services exist only in the separate **Pro** product (sold on [pickybooking.com](https://pickybooking.com/#pricing)) and require explicit admin configuration there.

== External services ==

This free plugin does not connect to third-party APIs. Booking data stays on your site. Bundled documentation uses system fonts only (no Google Fonts or other remote stylesheets).

== Installation ==

1. Upload the `pickybooking` folder to `/wp-content/plugins/` or install from Plugins → Add New.
2. Activate **PickyBooking** through the Plugins menu.
3. Open **PickyBooking** in the admin menu.
4. Create or import a layout from the Layouts library.
5. Place `[pickybooking id="YOUR_LAYOUT_ID"]` on a page.

Do not activate PickyBooking and PickyBooking Pro at the same time.

== Frequently Asked Questions ==

= Does Lite include Stripe payments? =

No. Card payments and deposits are part of PickyBooking Pro. Lite accepts booking requests and can notify you by email.

= Can I use decorative walls and icons in the builder? =

Decorative layers are Pro. Lite focuses on bookable square/circle items so guests can reserve seats, tables, desks, and similar items.

= Where is the documentation? =

After install: `wp-content/plugins/pickybooking/docs/` or **PickyBooking → Documentation** in wp-admin.

= Is this trialware? =

No. Every feature listed as free works without a license key or time limit. Pro is a separate commercial plugin.

== Screenshots ==

1. Guest booking: pick a seat, desk, or table on the floor plan.
2. Booking form with date, time, and optional services.
3. Admin reservations calendar.

== Changelog ==

= 1.0.18 =
* Plugin Check: sanitize the query string with wp_strip_all_tags() before it is parsed. Each parameter is still passed through sanitize_text_field().

= 1.0.17 =
* Phone booking dialog shows every time slot, and the thank-you message replaces the form.
* Plugin Check: the query string is split into parameters, then each value is sanitized.

= 1.0.16 =
* WordPress.org review: stop unverified GET requests from changing booking data, and sanitize request values before they are used.

= 1.0.15 =
* WordPress.org review: escape shortcode output, require nonces on layout create/delete, sanitize admin input, and escape late on echoed HTML.

= 1.0.14 =
* WordPress.org review: Select2 4.1.0, prefixed layout admin slug, enqueue for admin and shortcode CSS/JS.

= 1.0.13 =
* Stop calling load_plugin_textdomain(); WordPress.org loads translations automatically.

= 1.0.12 =
* Plugin URI and Author URI are now different (WordPress.org header requirement).

= 1.0.11 =
* Fix Save layout fatal (missing sanitizer) and apply Layout Colors on the frontend after picky-frontend.css.

= 1.0.10 =
* Translation text domain is pickybooking (matches the WordPress.org plugin slug).

= 1.0.9 =
* WordPress.org listing: contributor gogetthemes, docs use system fonts only, load textdomain.

= 1.0.8 =
* Save and query bookings with prefixed statuses so new reservations occupy the slot and show in admin.

= 1.0.7 =
* Escape datepicker data attributes and time options; restrict canvas text colors to hex.

= 1.0.6 =
* Fix Classic layout booking JS crash on the last step (data-time was encoded twice).

= 1.0.5 =
* Upgrade links go only to pickybooking.com. This free build does not include a third-party checkout.

= 1.0.4 =
* WordPress.org compliance: Pro-only code omitted from this zip (payments, AI, integrations, decorative layers, JSON import/export). Prefixes, licenses, and translation template updated.

= 1.0.3 =
* Rebranded to PickyBooking (plugin name, slug, text domain, shortcode).

= 1.0.2 =
* WordPress.org review follow-up: enqueue hardening, sanitization, Select2 stable, readme/external services docs.

= 1.0.1 =
* WordPress.org Plugin Check compliance and security hardening.

= 1.0.0 =
* Initial WordPress.org release (Lite).
