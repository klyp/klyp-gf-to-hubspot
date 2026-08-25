# Klyp Gravity Form to Hubspot

Map Gravity Forms fields to Hubspot form fields and deliver every submission to Hubspot.

![version](https://img.shields.io/badge/version-2.0.0-blue)
![wordpress](https://img.shields.io/badge/wordpress-6.0%2B-21759b)
![php](https://img.shields.io/badge/php-8.0%2B-777bb4)
![gravity%20forms](https://img.shields.io/badge/gravity%20forms-2.5%2B-f15a29)
![license](https://img.shields.io/badge/license-GPL--2.0--or--later-green)
![tests](https://img.shields.io/badge/tests-46%20passing-brightgreen)

Licensed under GPL-2.0-or-later. See [License & Compliance](#license--compliance).

---

## Table of Contents

- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Usage](#usage)
- [Project Structure](#project-structure)
- [Development Setup](#development-setup)
- [Testing](#testing)
- [Release Process](#release-process)
- [Contributing](#contributing)
- [Security](#security)
- [License & Compliance](#license--compliance)
- [Support & Contact](#support--contact)
- [Changelog](#changelog)
- [Acknowledgments](#acknowledgments)

---

## Overview

A WordPress plugin that connects a Gravity Form to a Hubspot form. Each Gravity Forms field is mapped to a Hubspot property in the form editor; on submission the plugin builds a Hubspot payload from the entry and posts it to the Hubspot Forms submission endpoint.

### Key features

- **Per-field mapping in the form editor.** Every supported field gets a *Hubspot field to map* setting under its **Advanced** tab, populated live from the Hubspot form.
- **Nothing fails quietly.** If Hubspot rejects a submission, the form does not submit — the visitor sees an error and no entry, notification or confirmation is created.
- **Pre-flight payload validation.** The payload is checked against the Hubspot form definition before it is sent. Hubspot answers a submission carrying an invalid dropdown option with `HTTP 200` and then discards it, so such a submission would otherwise vanish with no error raised anywhere.
- **Full request tracing.** Every submission writes its payload and Hubspot's response to `wp-content/debug.log`.
- **Correct value shaping** for checkbox, multi-select, consent, list, date and multi-input (Name, Address) fields, including boolean coercion for Hubspot `single_checkbox` properties.
- **Cached form definitions** (15 minutes) so the form editor does not call the Hubspot API once per field.

### Scope boundaries

This plugin does **not**:

- install or manage the Hubspot tracking script (use Hubspot's own plugin for that);
- create or update Hubspot records through the CRM API — it submits to a **Hubspot form**, and Hubspot decides what records result;
- retry failed submissions or queue them for later;
- map a single Gravity Forms multi-input field (Name, Address) to more than one Hubspot property — the sub-values are joined into one value.

### How it works

Two independent Hubspot endpoints are used:

| Purpose | Endpoint | Auth |
|---|---|---|
| Read a form's field list (admin) | `https://api.hubapi.com/marketing/v3/forms/{formId}` | Private App bearer token |
| Submit an entry (front end) | `https://api.hsforms.com/submissions/v3/integration/submit/{portalId}/{formGuid}` | None |

Submission runs on the `gform_validation` filter, so a Hubspot rejection fails validation and stops the form. A success is recorded as a note on the saved entry via `gform_after_submission`.

---

## Prerequisites

| Requirement | Version | Notes |
|---|---|---|
| WordPress | 6.0+ | `[ASSUMPTION]` No minimum is enforced in code; 6.0 reflects the `readme.txt` "Requires at least". |
| PHP | 8.0+ | Declared in the plugin header. |
| Gravity Forms | 2.5+ | Enforced at runtime; below this the plugin refuses to load and shows an admin notice. |

### Required accounts and credentials

| Credential | Purpose |
|---|---|
| Hubspot **Portal ID** | Identifies the Hubspot account in the submission URL. |
| Hubspot **Private App access token** | Reads form definitions from the Marketing Forms v3 API. Requires the `forms` scope. |

> Both are entered in the WordPress admin and stored as options. Hubspot API keys (`hapikey`) are no longer accepted by Hubspot and are not supported.

**Never commit credentials.** No environment variables are required — configuration lives in the WordPress options table.

---

## Installation

### Via Composer (recommended for Klyp projects)

The repository ships no `composer.json`, and its tags are named `v.1.0.5` / `v.1.0.4`, which Composer cannot parse as versions — so a plain `vcs` repository cannot load it. Declare the release inline instead:

```json
{
  "repositories": [
    {
      "type": "package",
      "package": {
        "name": "klyp/klyp-gf-to-hubspot",
        "version": "1.0.5",
        "type": "wordpress-plugin",
        "dist": {
          "type": "zip",
          "url": "https://github.com/klyp/klyp-gf-to-hubspot/archive/refs/tags/v.1.0.5.zip"
        },
        "source": {
          "type": "git",
          "url": "https://github.com/klyp/klyp-gf-to-hubspot.git",
          "reference": "v.1.0.5"
        },
        "require": { "composer/installers": "^2.0" }
      }
    }
  ],
  "require": {
    "klyp/klyp-gf-to-hubspot": "1.0.5"
  }
}
```

```bash
composer update klyp/klyp-gf-to-hubspot
```

With `composer/installers` and a `type:wordpress-plugin` installer path, the plugin lands in `wp-content/plugins/klyp-gf-to-hubspot/`.

> `[ASSUMPTION]` The snippet pins `1.0.5` because that is the newest published tag. Version 2.0.0 is not yet tagged — see [Release Process](#release-process). Bump both the `version`/`reference` fields and the constraint once `v2.0.0` exists.

### Manual

```bash
cd wp-content/plugins
git clone git@github.com:klyp/klyp-gf-to-hubspot.git
```

Or upload the plugin directory to `wp-content/plugins/klyp-gf-to-hubspot/`.

### Configure

1. Activate **Klyp Gravity Form to Hubspot** in **Plugins**.
2. In Hubspot, create a Private App (**Settings → Integrations → Private Apps**) with the `forms` scope and copy its access token.
3. In WordPress, go to **Settings → Klyp Gravity Form to Hubspot** and enter the Hubspot **Portal ID** and **Private App access token**.

### Verify

1. Open a Gravity Form → **Form Settings → Hubspot Settings**. Enter the Hubspot form GUID and save.
2. Re-open the form editor and select any field. Under **Advanced**, the *Hubspot field to map* dropdown should list the Hubspot form's properties. If it shows an error notice instead, the token or form GUID is wrong.
3. Submit the form and confirm `wp-content/debug.log` contains a matching `klyp-gf-to-hubspot: entry ... response HTTP 200` line.

---

## Usage

### Mapping a form

1. **Form Settings → Hubspot Settings** — enter the **Hubspot Form ID** (GUID) and save. Saving is required before the field lists can load.
2. Optionally set **Email field used in gravity form** and **Email field used in Hubspot**. This guarantees the email address reaches Hubspot even when the Gravity Forms email field carries no mapping of its own.
3. In the form editor, select a field → **Advanced** → **Hubspot field to map**.

Supported field types: `checkbox`, `consent`, `date`, `email`, `hidden`, `multiselect`, `name`, `number`, `phone`, `radio`, `select`, `text`, `textarea`, `time`, `website`.

### Value handling

| Gravity Forms field | Sent to Hubspot as |
|---|---|
| Checkbox | Selected option values, `;` delimited |
| Multi Select | JSON decoded, `;` delimited |
| Consent | `true` / `false` |
| Date | `YYYY-MM-DD` (as stored in the entry) |
| List | Flattened, `;` delimited |
| Name / Address | Non-empty sub-values joined with a space |
| Any field mapped to a Hubspot `single_checkbox` | `true` / `false`, always sent |

Two Gravity Forms fields mapped to the same Hubspot property collapse into one value (last one wins) rather than producing a duplicate-field error.

### Failure behaviour

A Hubspot failure fails validation and blocks the submission. Where Hubspot names the property it rejected, the error is shown on the Gravity Forms field mapped to it; otherwise a generic message appears above the form. Technical detail always goes to `wp-content/debug.log`, and to the Gravity Forms log when Gravity Forms logging is on.

If the Hubspot field list cannot be loaded, the pre-flight check is skipped rather than blocking every submission — Hubspot's own response then decides the outcome.

### Caching

Hubspot form definitions are cached for 15 minutes. Clear them with the **Clear cached Hubspot fields** button on the settings screen after changing a form in Hubspot.

### Filters

| Filter | Default | Purpose |
|---|---|---|
| `klyp_gftohs_send_page_context` | `false` | Send `pageUri` / `pageName`. **Off by default:** Hubspot spam-filters a submission whose page URL is on a domain the portal does not recognise — returning `HTTP 200` and discarding it. Enable only on a tracked domain. |
| `klyp_gftohs_error_message` | generic message | Wording shown above the form when a failure cannot be attributed to a field. |
| `klyp_gftohs_mappable_field_types` | see list above | Which Gravity Forms field types get the mapping setting. |
| `klyp_gftohs_submission_payload` | payload array | Modify the payload before it is sent. |
| `klyp_gftohs_api_base` | `https://api.hubapi.com/` | Override the authenticated API base URL. |

```php
// Send page context on a domain the Hubspot portal tracks.
add_filter('klyp_gftohs_send_page_context', '__return_true');
```

---

## Project Structure

```
klyp-gf-to-hubspot/
├── klyp-gf-to-hubspot.php   Plugin header; Gravity Forms version gate; loads inc/
├── inc/
│   ├── hubspot-api.php      klypHubspot client — field lookup, payload building,
│   │                        validation, submission, logging
│   ├── hubspot.php          Gravity Forms admin UI — form settings and the per-field
│   │                        mapping control
│   ├── gf.php               Submission handling on gform_validation; error surfacing
│   ├── settings.php         Settings page registration, sanitising, cache flushing
│   └── settings-page.php    Settings screen markup
├── tests/
│   ├── bootstrap.php        WordPress + Gravity Forms stubs
│   └── test-hubspot-api.php Assertions for the API client and payload building
├── assets/js/main.js        Reserved admin asset (currently unused)
├── readme.txt              WordPress plugin readme
└── README.md               This file
```

---

## Development Setup

```bash
git clone git@github.com:klyp/klyp-gf-to-hubspot.git
cd klyp-gf-to-hubspot
```

There is no build step and no dependencies to install — the plugin is plain PHP. Symlink or copy the directory into a WordPress install's `wp-content/plugins/` to work on it.

### Coding standards

PSR-12 with the WordPress exception of `snake_case` for variables and function names; 4-space indent; PHPDoc on functions and classes; no closing `?>` in pure-PHP files.

> `[ASSUMPTION]` No `phpcs.xml` ships with this repository, so standards are stated rather than enforced. Adding one would make them checkable.

### Branching

Klyp promotion flow: feature branch → `release-develop` → `develop` (first review), and `master` → `release-master` → `master` (second review). Branches present here are `develop`, `master` and `release-master`. Commits follow Conventional Commits (`feat`, `fix`, `perf`, `chore`, `docs`, `test`, `security`).

---

## Testing

The API client and payload building are covered by a standalone suite that stubs WordPress and Gravity Forms, so no WordPress install or database is needed:

```bash
php tests/test-hubspot-api.php
```

```
46 passed, 0 failed
```

It exits non-zero on failure, so it can be wired into CI as-is. Run it with strict error reporting to catch deprecations:

```bash
php -d error_reporting=E_ALL tests/test-hubspot-api.php
```

Coverage includes payload building per field type, the email guarantee, context handling, Hubspot error parsing, field discovery and caching, boolean checkbox coercion, and pre-flight option validation.

> `[ASSUMPTION]` No CI configuration ships with this repository and no coverage tool is configured, so no build or coverage badge reflects a real pipeline.

---

## Release Process

The plugin is consumed by host projects through Composer as an inline `package` repository pinned to a git tag (see [Installation](#installation)).

To cut a release:

1. Bump `Version:` in `klyp-gf-to-hubspot.php`, `Stable tag:` in `readme.txt`, and the [Changelog](#changelog).
2. Merge through the Klyp flow to `master`.
3. Tag the release and push the tag.
4. Update the consuming project's `composer.json` — the package `version`, the `dist` URL, the `source` `reference`, and the `require` constraint — then `composer update klyp/klyp-gf-to-hubspot`.

> **Tag naming.** Existing tags are `v.1.0.5` and `v.1.0.4`. The `v.` prefix is not parseable by Composer's version parser, which is why the package must be declared inline rather than through a `vcs` repository. Tagging future releases as `v2.0.0` (or `2.0.0`) would allow a plain `vcs` repository and normal version constraints.

---

## Contributing

This is a Klyp-internal plugin. The repository is publicly visible, but development follows the internal flow rather than an open fork-and-PR model.

- **Report a bug or request a change:** open an issue at <https://github.com/klyp/klyp-gf-to-hubspot/issues>.
- **Submit a change:** branch from the appropriate base, follow the Conventional Commits convention, and open a merge/pull request into `release-develop`. Every change is reviewed before promotion to `develop`, and again before `master`.
- Run `php tests/test-hubspot-api.php` before opening a request, and add assertions for behaviour you change.

> `[ASSUMPTION]` No `CONTRIBUTING.md` exists in this repository, so the process above is documented inline instead of linked.

---

## Security

### Reporting a vulnerability

Report suspected vulnerabilities privately to the Klyp development team via <https://klyp.co> rather than opening a public issue.

> `[ASSUMPTION]` No `SECURITY.md` or published security contact exists for this repository. Adding one would give reporters an unambiguous channel.

### Considerations

- The **Private App access token** is stored unencrypted in the WordPress options table, as WordPress provides no secret store. Restrict its scope to `forms` only, and treat database dumps as containing a live credential.
- The settings screen requires the `manage_options` capability, and the cache-flush action is nonce-protected.
- Both settings have sanitise callbacks; the portal ID is reduced to digits.
- Hubspot-supplied labels and values are escaped before being rendered in the admin.
- The authenticated API base is fixed to `https://api.hubapi.com/` and only changeable through the `klyp_gftohs_api_base` filter, so a compromised option cannot redirect credentialed requests.
- **Submission payloads are written to `wp-content/debug.log`** when `WP_DEBUG_LOG` is enabled. Those payloads contain submitted personal data — ensure the log is not web readable and is rotated.

---

## License & Compliance

Released under the **GNU General Public License v2.0 or later**, consistent with WordPress and Gravity Forms.

> `[ASSUMPTION]` The plugin header states `GPL2` and `readme.txt` states `GPL2+`, but the repository contains no `LICENSE` file and GitHub reports no detected license. Adding a `LICENSE` file with the full GPL-2.0-or-later text would resolve the ambiguity — no link is given here because the file does not exist.

### Data privacy

This plugin transmits **personal data** — typically name, email address, phone number and free-text message — from Gravity Forms entries to Hubspot, a third-party processor. When deploying it:

- disclose the transfer in the site's privacy policy;
- confirm the Hubspot account's data residency meets the site's obligations;
- ensure any consent field is mapped so the visitor's choice reaches Hubspot;
- remember that submitted values appear in `debug.log` when debug logging is on.

### Third-party services

| Service | Used for |
|---|---|
| Hubspot Marketing Forms API v3 | Reading form definitions (authenticated) |
| Hubspot Forms submission API | Delivering submissions (unauthenticated) |

---

## Support & Contact

| Need | Where |
|---|---|
| Bug reports, feature requests | <https://github.com/klyp/klyp-gf-to-hubspot/issues> |
| Klyp development team | <https://klyp.co> |

### Troubleshooting

| Symptom | Likely cause |
|---|---|
| Mapping dropdown shows "Hubspot fields could not be loaded" | Missing/invalid Private App token, wrong form GUID, or the token lacks the `forms` scope. |
| Form blocks with "not one of the accepted options" | A mapped field sends free text to a Hubspot dropdown. Map it to a text property or send a valid option value. |
| Hubspot returns `HTTP 200` but nothing appears in Hubspot | Page context sent from a domain the portal does not recognise. Confirm `klyp_gftohs_send_page_context` is off, and check `debug.log` for the payload. |
| Hubspot reports a required field missing | The Hubspot form marks a property required but the Gravity Forms field is optional and was left empty. |
| Form editor shows stale Hubspot fields | Use **Clear cached Hubspot fields** on the settings screen. |

---

## Changelog

This project follows [Semantic Versioning](https://semver.org/).

### 2.0.0 — 2026-08-25

Compatibility release for Gravity Forms 2.9 and PHP 8.3.

- Replaced sunset Hubspot `hapikey` authentication and the Forms v2 API with a Private App access token against the Marketing Forms v3 API. **Breaking:** the API key and base URL settings are removed; a Private App access token setting is added and must be configured.
- Fixed the form editor and form settings screens halting mid-render when Hubspot returned an error.
- Fixed a fatal error that took the whole site down when Gravity Forms was deactivated.
- A Hubspot failure now blocks the form and shows the visitor an error instead of the submission appearing to succeed.
- Added pre-flight payload validation against the Hubspot form definition.
- `pageUri` / `pageName` are no longer sent by default; opt in with `klyp_gftohs_send_page_context`.
- Fixed multi-input, multi-select, consent, list and boolean checkbox value handling.
- Every submission's payload and response is written to `wp-content/debug.log`.
- Resolved PHP 8 deprecations; added a standalone test suite.

### 1.0.5 — 2022-03-24
Added support for the consent field.

### 1.0.4 — 2022-03-22
Added support for date, time, name and website fields.

### 1.0.3 — 2022-03-18
Support for Gravity Forms 2.5 and above.

### 1.0.2 — 2022-01-17
Stop submission if validation fails.

### 1.0.1 — 2021-10-05
Support for more fields, and PHP 8.

### 1.0.0 — 2021-09-20
Initial release.

> `[ASSUMPTION]` No `CHANGELOG.md` exists, so history is summarised inline from the plugin `readme.txt` and repository history.

---

## Acknowledgments

- [Gravity Forms](https://gravityforms.com/) — the form engine this plugin extends.
- [Hubspot Forms API](https://developers.hubspot.com/docs/api/marketing/forms) — form definitions and submission handling.
- Built and maintained by [Klyp](https://klyp.co).
