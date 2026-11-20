# Changelog

## 1.0.29

Fix frontend floor-plan scaling when the canvas wrapper width collapses under transform (map was squashed to the left).

## 1.0.28

Fix frontend map crash from a missing pickyShowPaymentReturnNotice call so the booking canvas initializes.

## 1.0.27

Setup Checklist under Settings, clearer free amount calculation vs Pro online payment UI, Payments radio tiles matched to Main, shared Pro badge styling, and brand icons for provider cards. Lite Pro areas are upsell stubs only (no working Pro forms).

## 1.0.26

Fix admin menu icon/label spacing and keep the settings rail on-screen in narrow Playground viewports.

## 1.0.25

Use a short admin menu / settings-rail label so the long WP.org Plugin Name no longer wraps and overlaps the sidebar.

## 1.0.24

Plugin Check: document nonce verification on frontend POST helpers (sniff does not follow helper methods). Exclude hidden files from the distribution zip.

## 1.0.23

Final EscapeOutput pass: remove phpcs:ignore escapes, confirm dialogs via data-picky-confirm, and wp_kses for settings shell markup.

## 1.0.22

Escape embedded admin list markup with wp_kses instead of phpcs:ignore.

## 1.0.21

WordPress.org review follow-up: escape remaining builder/frontend attribute ternaries and related late-output strings.

## 1.0.20

WordPress.org review follow-up: escape remaining HTML attributes, verify settings/booking nonces before other POST reads, and check edit-redirect nonces before reading post IDs.

## 1.0.19

WordPress.org review: verify nonces before reading request input on admin AJAX and form handlers, and escape plugin font URLs in inline CSS.

## 1.0.18

Plugin Check: the query string is sanitized with wp_strip_all_tags() before parsing. Each parameter is still passed through sanitize_text_field().

## 1.0.17

Phone booking dialog shows every time slot, and the thank-you message replaces the form. Query string values are sanitized after parsing.

## 1.0.13

Stop calling load_plugin_textdomain(); WordPress.org loads translations automatically.

## 1.0.12

Plugin URI and Author URI are different (WordPress.org header requirement).

## 1.0.11

Fix Save layout fatal and apply Layout Colors on the frontend after picky-frontend.css.

## 1.0.3

PickyBooking WordPress.org Lite (new slug/name).

## 1.0.0

PickyBooking booking system for WordPress.
