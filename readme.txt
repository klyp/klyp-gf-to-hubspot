=== Klyp Gravity Form to Hubspot ===
Contributors: klyp
Tags: contact, form, gravity, forms, hubspot
Requires at least: 6.0
Tested up to: 7.0.4
Requires PHP: 8.0
Stable tag: 2.1.0
License: GPL2+
License URI: https://www.gnu.org/licenses/gpl-2.0.txt

Klyp Gravity Form to Hubspot

== Description ==
This plugin allows you to map Gravity Forms fields to Hubspot form fields.

Requires Gravity Forms 2.5 or newer and a Hubspot Private App access token with
the "forms" scope.

== Changelog ==
v2.1.0 - 2026-09-04
Sends the page URL and title with every submission again, so Hubspot records the conversion page. Note that Hubspot discards a submission whose page URL is on a domain the portal does not recognise, answering HTTP 200 and recording nothing; on such an environment, typically a local development host, return false from the klyp_gftohs_send_page_context filter so submissions still reach Hubspot without the conversion page.

v2.0.0 - 2026.08.25
Compatibility release for Gravity Forms 2.9 and PHP 8.3. Replaced the sunset Hubspot API key authentication and Forms v2 API with a Private App access token against the Marketing Forms v3 API; the API key and base URL settings are removed and a token must be configured. Fixed the admin screens halting when Hubspot returned an error, and a fatal error when Gravity Forms was deactivated. A Hubspot failure now blocks the submission and shows the visitor an error instead of appearing to succeed, and the payload is validated against the Hubspot form before sending. Page context is no longer sent by default, which was causing Hubspot to accept and then silently discard submissions. Every submission is logged. Fixed multi-input, multi select, consent, list and boolean checkbox values. Rebuilt the settings screen with a live connection check and a Hubspot form picker. See README.md for the full list.

v1.0.5 - 2022.03.24
Added support for consent field

v1.0.4 - 2022.03.22
Added support for date, time, name and website fields

v1.0.3 - 2022.03.18
Support for Gravity Forms v2.5 and above

v1.0.2 - 2022.01.17
Stop submission if validation fails

v1.0.1 - 2021.10.05
Support for more fields, and php 8

v1.0.0 - 2021.09.20
Initial release
