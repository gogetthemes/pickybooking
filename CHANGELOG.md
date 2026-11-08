# Changelog

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
