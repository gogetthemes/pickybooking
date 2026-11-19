=== PickyBooking – Visual Booking Map ===
Contributors: gogetthemes
Donate link: https://pickybooking.com/
Tags: booking, reservation, floor plan, table booking, appointment
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.26
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visual floor-plan bookings for tables, seats, desks, and more. Embed with a shortcode and manage reservations by email.

== Description ==

**PickyBooking** turns a floor plan into a booking page. Guests pick a table, seat, desk, chair, or other bookable item on a visual map, choose a time, and send a reservation — all on your WordPress site.

Live demos (open any industry map and book as a guest):

* **[Restaurant](https://pickybooking.com/restaurant-demo-booking/)** — floor plan with table picking, party size, and time slots
* **[Coworking](https://pickybooking.com/coworking-booking/)** — desks and rooms on one interactive layout
* **[Cinema](https://pickybooking.com/cinema-demo/)** — auditorium seat map with live availability
* **[Wedding ceremony](https://pickybooking.com/wedding-ceremony-demo-booking/)** — numbered seats and ceremony seating chart
* **[Beauty salon](https://pickybooking.com/beauty-salon-demo-booking/)** — chairs, services, and appointment flow
* **[Football / sports](https://pickybooking.com/football-match-player-demo-booking/)** — field or player-spot booking with schedules
* **[EV charging](https://pickybooking.com/ev-charging-station-booking-map-demo/)** — reserve a charging bay on a station map

More demos: [pickybooking.com/demos](https://pickybooking.com/demos/)

= Free features (this plugin) =

Everything below works without a license key or time limit:

* Drag-and-drop layout builder for **bookable** items (square and circle)
* Guest booking shortcode: `[pickybooking id="LAYOUT_ID"]`
* Booking wizards: **Classic** (pick item first) and **Simple** (no map, time only)
* Exclusive or capacity booking modes
* Weekly opening hours, booking horizon, default duration, and buffers
* Form fields (name, email, phone, party size, notes, consent, and more)
* Layout colors / appearance settings
* Mobile map modes (scaled map, list, or hide map)
* Admin calendar and bookings list
* Email notifications via WordPress `wp_mail`
* Start from a blank layout (free curated demos can be added over time)
* In-plugin documentation and Setup Checklist
* WPML-ready UI strings (text domain `pickybooking`; layouts stay shared across languages)

= PickyBooking Pro (separate plugin) =

Pro is **not** locked inside this free zip. It is a separate commercial plugin with extra features:

* Smart and Smart Map booking wizards
* Curated Pro layout presets (restaurant, cinema, coworking, salon, EV, wedding, sports, and more)
* Stripe Checkout and WooCommerce deposits / payments
* Decorative builder layers (walls, text, shapes, icons)
* Booked guest avatars on the map
* SMS notifications (Vonage)
* AI layout drafting helpers
* HubSpot, Mailchimp, Google Calendar, Zapier, Make, and n8n webhooks
* Layout JSON import / export
* Elementor widget
* White-label admin branding

Get Pro: [pickybooking.com/#pricing](https://pickybooking.com/#pricing)

= Privacy =

Booking data stays in your WordPress database. Emails use `wp_mail`. This free plugin does not call third-party APIs. Bundled docs use system fonts only (no Google Fonts or remote stylesheets).

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

1. Layout builder — drag bookable items onto a floor plan (Beauty Salon example) and copy the shortcode.
2. Guest map — interactive floor plan with stations, rooms, and waiting areas.
3. Classic booking — pick a spot on the map, then date and time in the wizard.
4. Simple booking — date and time without choosing a specific map item.
5. Date & time step — calendar and time-slot grid in the booking wizard.
6. Layouts library — your saved floor plans, Start from blank, and Pro preset previews.

== Changelog ==

= 1.0.26 =
* Fix admin menu icon/label spacing and keep the settings rail on-screen in narrow Playground viewports.

= 1.0.25 =
* Use a short admin menu / settings-rail label so the long WP.org Plugin Name no longer wraps and overlaps the sidebar.

= 1.0.24 =
* Plugin Check: clarify nonce verification on frontend POST helpers; omit hidden files from the distribution zip.

= 1.0.23 =
* Final EscapeOutput pass: remove phpcs:ignore escapes, confirm dialogs via data-picky-confirm, and wp_kses for settings shell markup.

= 1.0.22 =
* Escape embedded admin list markup with wp_kses instead of phpcs:ignore.

= 1.0.21 =
* WordPress.org review follow-up: escape remaining builder/frontend attribute ternaries and related late-output strings.

= 1.0.20 =
* WordPress.org review follow-up: escape remaining admin/frontend HTML attributes, verify settings and booking nonces before other POST reads, and check edit-redirect nonces before reading post IDs.

= 1.0.19 =
* WordPress.org review: verify nonces before reading POST/GET on admin AJAX and form handlers, and escape plugin font URLs in inline CSS.

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
