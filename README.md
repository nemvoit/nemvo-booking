# Nemvo Booking

Meeting booking for WordPress. Visitors pick a time from your team's real Google Calendar availability. Each booking gets a Google Meet link, and everyone involved receives a calendar invitation.

Bookings are stored on your own site. There is no booking service in between: the plugin only talks to Google, through your own Google Cloud project.

Requires WordPress 6.2 or later and PHP 7.4 or later. MIT licensed.

## Features

- **Booking pages:** create as many as you like, each with its own hosts. For example, one page for you, one for a colleague and one that books you both. Every host attends, and a time is only offered when all of them are free. One host is the organizer, and their calendar holds the meeting.
- **Host photos** at the top of the booking page, on by default and switchable per page. Each host's photo comes from the media library, with their Gravatar as fallback.
- **Google Calendar per host:** connected with OAuth, either by an admin or through a 7-day connect link sent to the host, who doesn't need a WordPress account. You choose which calendars to check for conflicts and which calendar new meetings go into.
- **Busy-aware availability:** Google's free/busy data is used, so only events marked *Busy* block a time. A free all-day event doesn't block anything.
- **A Google Meet link** on every meeting.
- **One email per person:** the calendar invitation itself. You choose who sends it:
  - **Google Calendar** (default). Google emails the booker, guests and other hosts. The invitation text (the form answers plus reschedule and cancel links) can be edited in Settings. WordPress only emails the organizing host, because Google doesn't notify organizers of their own events.
  - **WordPress.** Each person gets one email you can edit, with an `.ics` invitation attached that mail apps show with Accept and Decline buttons.
- **Booking rules:**
  - Form questions of your own (name, email, company, job title, phone and a message by default), plus optional guests.
  - Meeting lengths, start-time interval, minimum notice, how far ahead people can book, and buffers.
  - Weekly opening hours with breaks, in each booking page's time zone.
  - Public holidays from one or more countries (Google's holiday calendars, plus any extra calendar IDs), and closed dates.
- **A Monday-first month calendar** with optional ISO week numbers. Times are shown in the visitor's own time zone.
- **Emails you can edit:** booking, reschedule and cancellation emails for bookers, guests and hosts. Each one has its own subject and text with placeholders, and can be switched off. After booking, visitors see a thank-you message, or are sent to a page of your choice.
- **Rescheduling and cancelling:** the booker gets personal links in the confirmation email. Rescheduling moves the same calendar event, so the Meet link stays the same, and everyone gets the updated invitation. Admins can also reschedule or cancel from the booking in wp-admin. Rescheduling can be switched off per booking page.
- **Connection alerts:** if a host's Google access stops working (password changed, access revoked, app left in Testing mode, calendar deleted), the admin and the host get one email with a reconnect link, and a warning shows in wp-admin until it's fixed. Connections are also checked daily, so problems are caught even without visitors.
- **GDPR:**
  - Optional consent checkbox linking to your privacy policy. The time of consent is stored.
  - Personal data is deleted automatically a set number of days after the meeting (365 by default).
  - Bookings are included in WordPress's *Export Personal Data* and *Erase Personal Data* tools.
  - Suggested text is added to WordPress's privacy policy guide.
- **History log** and a bookings list in wp-admin.
- **Email** goes through `wp_mail()`, so SMTP plugins such as WP Mail SMTP work as usual. WP Mail SMTP's "Force From" options override the sender set in this plugin.
- **Spam protection:**
  - Honeypot.
  - Signed time-to-submit check.
  - Headless browser detection.
  - Per-IP rate limit.
  - Blocklist.
  - Optional [Cap](https://trycap.dev), a proof-of-work CAPTCHA you host yourself. The Cap widget is bundled with the plugin, so visitors' browsers load nothing from a CDN.

## Installation

Download `nemvo-booking-<version>.zip` from the [latest release](https://github.com/nemvoit/nemvo-booking/releases/latest) and upload it under **Plugins → Add New Plugin → Upload Plugin**. Once the plugin is in the WordPress.org directory, you can also search for *Nemvo Booking* there.

Then:

1. Go to **Bookings → Settings** and follow the step-by-step guide to connect the site to Google. You create a free OAuth client in Google Cloud and paste in its Client ID and secret.
2. Under **Bookings → Employees**, add the people visitors can book, connect their calendars and optionally choose a photo.
3. Under **Bookings → Booking pages**, create a booking page.
4. Add the **Booking page** block to a page, or use the shortcode `[nemvo_booking id="123"]`.

Google only accepts `https://` redirect addresses, so the site needs HTTPS (`http://localhost` also works, for testing). The guide for running Cap with Docker and Valkey is in Settings, under *Spam and abuse protection*.

## Adding a booking page to your site

**Block editor** (recommended):

1. Edit or create a page.
2. Click **+**, search for **Booking page** and insert the block.
3. Pick the booking page in the block.
4. Optionally, choose **Wide width** in the block toolbar.

**Everything else** (classic editor, Shortcode block, widgets, page builders, theme hooks): paste the shortcode shown on the booking page, for example `[nemvo_booking id="123"]`.

The calendar sizes itself to the space it gets. From about 900 px wide it shows the info panel, calendar and times side by side; in narrower columns and on phones they stack. Every booking page in wp-admin lists the pages it appears on.

### GeneratePress

- In the page's **Layout** panel, set **Sidebars → No Sidebars** so the calendar gets the full content width.
- Keep **Content Container** on *Default*. *Full Width* removes the side padding, so only use it if a surrounding container adds its own width and padding.
- With GP Premium you can also place the calendar with **Elements**: create a Block element containing the Booking page block, or a Hook element containing the shortcode.
- GeneratePress styles all buttons globally. The widget's styles are scoped to override that, so no extra CSS is needed.

### GenerateBlocks

The Booking page block works inside GenerateBlocks **Container**, **Grid** and **Element** blocks like any other block. To build a landing section, wrap it in a Container with a max width (e.g. 1100 px) and padding, and put a heading or text beside or above it.

## Styling

The widget is styled with CSS custom properties (tokens). Restyle it from your theme, or from **Appearance → Customize → Additional CSS**, with one rule on `.nb-booking`:

```css
.nb-booking {
	--nb-accent: #0b5cad;         /* filled buttons, selected day */
	--nb-link: #0a4f94;           /* darker shade for small text, for contrast */
	--nb-font: "Inter", sans-serif;
	--nb-font-heading: "Fraunces", serif;
	--nb-font-size: 16px;         /* every size scales with it */
	--nb-radius: 4px;
	--nb-radius-control: 4px;
	--nb-radius-button: 999px;
	--nb-shadow: none;
}
```

No `!important` is needed, and it works whatever order the stylesheets load in: the defaults have zero specificity (`:where(.nb-booking)`), so any `.nb-booking` rule wins. Set the tokens on `.nb-booking` itself, not on `:root` or `body`. The widget declares its defaults on `.nb-booking`, so values set on an ancestor don't reach it.

The **Accent color** setting (Bookings → Settings) sets `--nb-accent` at the same zero specificity, so a theme rule still overrides it. Emails always use the setting.

### Filled vs. text and outline states

- **Filled** states use `--nb-accent` with `--nb-on-accent` text: the selected day, a hovered or focused time slot, and `.nb-button`. The Cancel meeting button (`.nb-button--danger`) is filled with `--nb-danger` and has its own text color, `--nb-on-danger`.
- **Text and outline** states use `--nb-link`, with `--nb-link-soft` for their tinted backgrounds: available days, time slots, the active duration pill, the month arrows, `.nb-link` links and the calendar icon in the booking summary.

`--nb-link` defaults to `--nb-accent`. Set it separately when your accent color doesn't have enough contrast for small text on white.

### Tokens

| Token | Default | Affects |
|---|---|---|
| `--nb-accent` | `#4f46e5`, or the Accent color setting | Filled states (see above). The base of `--nb-accent-hover`, `--nb-link` and `--nb-focus`. |
| `--nb-accent-hover` | `--nb-accent` 8% brighter | `.nb-button` background on hover |
| `--nb-accent-soft` | `--nb-accent` at 12% | Not used by the widget; available for your own CSS |
| `--nb-on-accent` | `#fff` | Text on accent fills: selected day, hovered or focused time slot, `.nb-button` |
| `--nb-link` | `var(--nb-accent)` | Text and outline states (see above), the consent checkbox link and the ghost button ("Join with Google Meet") |
| `--nb-link-soft` | `--nb-link` at 12% | Tinted backgrounds of available days, month arrows, the active pill and the ghost button |
| `--nb-link-soft-hover` | `--nb-link` 8% brighter, at 11.7% | Ghost button background on hover. Its text keeps `--nb-link`. |
| `--nb-focus` | `var(--nb-accent)` | Focus ring on every control (3px outline, 2px offset), and the border and halo of a focused input |
| `--nb-text` | `#1f2937` | Text and headings |
| `--nb-muted` | `#6b7280` | Secondary text: duration, Google Meet and time zone lines, weekday headings, unavailable days, Back, help text |
| `--nb-bg` | `#ffffff` | Card, inputs, time slots, inactive pills, the ring around host photos |
| `--nb-bg-soft` | `#f9fafb` | Booking summary box, host photos while they load |
| `--nb-border` | `#e5e7eb` | Card border and dividers, summary border, inactive pill border, disabled month arrow |
| `--nb-input-border` | `#d1d5db` | Borders of inputs, textareas and selects |
| `--nb-danger` | `#dc2626` | Error messages and borders, required asterisk, Cancel meeting button |
| `--nb-danger-hover` | `--nb-danger` 8% brighter | Cancel meeting button background on hover |
| `--nb-on-danger` | `#fff` | Cancel meeting button text. Not tied to `--nb-on-accent`. |
| `--nb-success` | `#059669` | Check mark after booking, "cancelled" confirmation |
| `--nb-radius` | `12px` | Card corners |
| `--nb-radius-control` | `8px` | Inputs, textareas and selects. The summary box uses 1.25 times this value (10px). |
| `--nb-radius-slot` | `var(--nb-radius-control)` | Time slot buttons, e.g. `999px` for pill-shaped slots |
| `--nb-radius-button` | `8px` | `.nb-button` |
| `--nb-shadow` | `0 1px 2px rgba(0,0,0,.04), 0 8px 24px rgba(0,0,0,.04)` | Card shadow |
| `--nb-font` | `inherit` | Font of the whole widget |
| `--nb-font-heading` | `inherit` | `.nb-title`, `.nb-step-title`, `.nb-month` and the thank-you message headings |
| `--nb-font-ui` | `inherit` | Buttons: `.nb-button`, pills, time slots, days, month arrows, Back |
| `--nb-font-size` | `15px` | Base font size. All other sizes are in `em`, so they scale with it. |
| `--nb-weight-medium` | `500` | Duration, Google Meet and time zone lines |
| `--nb-weight-strong` | `600` | Step titles, month name, weekday headings, time slots, labels, links, buttons, success message |
| `--nb-weight-title` | `700` | `.nb-title`, available days, thank-you message headings |

The defaults also set `color-scheme: light`, so native controls match the light card. For a dark card, set `color-scheme: dark` along with dark values for `--nb-bg`, `--nb-bg-soft`, `--nb-text`, `--nb-muted`, `--nb-border` and `--nb-input-border`.

Pills, days, month arrows, host photos and the check mark stay round whatever the radius tokens say. Host photos are 56px at the default font size and scale with `--nb-font-size`. The font size of thank-you message headings comes from the theme's `h1`–`h3` styles.

### Class names

You can target these classes directly. They stay stable between versions:

| Class | Element |
|---|---|
| `.nb-booking` | Root of the widget |
| `.nb-card`, `.nb-card--narrow` | The card; narrow for the cancel page and messages |
| `.nb-info`, `.nb-main` | Info panel (photos, title, duration, time zone) and main panel |
| `.nb-hosts`, `.nb-host` | Row of host photos and each photo (`<img>`, with the host's name as `alt`) |
| `.nb-title`, `.nb-meta`, `.nb-description` | Booking page title, the duration/Meet/time zone list, description |
| `.nb-durations`, `.nb-pill` (`.is-active`) | Duration choice |
| `.nb-tz` | Time zone picker (wraps the `<select>`) |
| `.nb-picker`, `.nb-calendar`, `.nb-times` | Date and time step, and its two columns |
| `.nb-step-title` | Step headings ("Select a date", the chosen date, "Your details") |
| `.nb-cal-head`, `.nb-nav`, `.nb-month` | Month header, arrow buttons, month name |
| `.nb-grid` (`.is-loading`), `.nb-wk` | Month table, week number cells |
| `.nb-day` (`.is-available`, `.is-selected`, `.is-today`, `.is-outside`) | Day buttons |
| `.nb-slots`, `.nb-slot` | Time slot list and buttons |
| `.nb-back` | Back button |
| `.nb-summary`, `.nb-current` | Booking summary box; the current time while rescheduling |
| `.nb-form`, `.nb-field` (`.nb-field--checkbox`, `.has-error`) | Booking form and its fields |
| `.nb-req`, `.nb-field-error`, `.nb-help`, `.nb-guests`, `.nb-cap` | Required asterisk, field error, help text, guest emails, CAPTCHA |
| `.nb-button` (`.nb-button--ghost`, `.nb-button--danger`) | Buttons |
| `.nb-link`, `.nb-instead` | Text buttons and links; "Pick a new time instead" |
| `.nb-error`, `.nb-success`, `.nb-muted`, `.nb-loading` | Messages and secondary text |
| `.nb-done`, `.nb-check`, `.nb-thanks` | Booking confirmation, its check mark and the thank-you message |
| `.nb-cancel` | Cancel page content |

Component rules use two classes (`.nb-booking .nb-slot`), and up to four for states such as `.nb-booking .nb-day.is-selected:hover`, so that global theme rules like `button:hover` don't change them. To restyle a component directly, match that specificity, or simply use the tokens. Leave `.nb-hp` (the honeypot field) alone.

### Cancel and reschedule page

When a cancel or reschedule link opens a page that doesn't contain the widget, the plugin shows a minimal standalone page. That page doesn't run `wp_head`, so theme styles don't load there. Add your fonts and token rule with the `nemvo_booking_standalone_head` action, which runs inside `<head>` after the plugin's stylesheet:

```php
add_action( 'nemvo_booking_standalone_head', function () {
	echo '<link rel="stylesheet" href="' . esc_url( get_stylesheet_directory_uri() . '/nemvo-booking.css' ) . '">';
} );
```

The page's own defaults (grey background, system font) are `:where(body){…}` and `.nb-booking{max-width:1100px;margin:0 auto}`, so anything you print there overrides them.

## Hooks

| Hook | Type | Arguments | When |
|---|---|---|---|
| `nemvo_booking_created` | action | `$booking`, `$page_config` | After a booking is created |
| `nemvo_booking_rescheduled` | action | `$booking`, `$old_booking` | After a booking is moved to a new time |
| `nemvo_booking_cancelled` | action | `$booking` | After a booking is cancelled |
| `nemvo_booking_standalone_head` | action | | Inside `<head>` of the standalone cancel and reschedule page |
| `nemvo_booking_client_ip` | filter | `$ip` | The visitor's IP address, stored with the booking and used for the rate limit, the blocklist and the log. Filter it to read the real address behind a proxy. |
| `nemvo_booking_capability` | filter | `$capability` | The capability needed for the Bookings menu, booking pages, employees and settings. Default `manage_options`. |
| `nemvo_booking_google_auth_url` | filter | `$url`, `$params` | The Google sign-in address a host is sent to when connecting their calendar |

Booker links have the form `https://<page>/?nemvo_booking_cancel=<token>` and `?nemvo_booking_reschedule=<token>`. The names used before 1.2.2, `nb_cancel` and `nb_reschedule`, are still accepted, so links in emails that were already sent keep working. Booking pages are the post type `nemvo_booking_page` (`nb_booking_page` before 1.2.2; existing pages are moved on update and keep their IDs).

## Privacy and external services

The plugin sends no data to its author. It connects to:

- **Google** (OAuth and the Calendar API), with your own OAuth client: to read when hosts are busy and which days are public holidays, and to create, move and cancel meetings with the booker's details.
- **Gravatar**, only for hosts without a chosen photo and only when avatars are switched on under Settings → Discussion: on booking pages that show host photos (the visitor's browser loads the image) and in wp-admin's host lists (the admin's browser loads it). With avatars off, wp-admin shows a bundled placeholder.
- **Your own Cap server**, if you switch Cap on. By default visitors' browsers reach it through WordPress, so it can stay on an internal network. Cap's instrumentation challenge runs a script sent by your Cap server in a sandboxed frame that is isolated from the page (`CAP_ISOLATE`), so site keys that block automated interactions, score behavior with blocking on, or block AI agents can't be used (they answer `instr_isolated`).
- **trycap.dev**, only when a visitor clicks the "Cap" credit or "Troubleshoot" link in the visible Cap widget. Both are plain links: the plugin stops the widget from adding page or browser details to them (`CAP_DISABLE_WIDGET_REF`, `data-cap-troubleshooting-url`).

The details, with links to the services' terms, are in [readme.txt](nemvo-booking/readme.txt) under *External services*.

## Development

`.devcontainer/` contains a complete environment: WordPress, MariaDB, Mailpit, Cap and Valkey. Open the folder in VS Code and choose **Reopen in Container**, or run it directly:

```sh
docker compose -f .devcontainer/docker-compose.yml up -d
docker compose -f .devcontainer/docker-compose.yml exec wordpress bash /workspace/.devcontainer/setup.sh
```

| Service   | URL                          | Login                  |
|-----------|------------------------------|------------------------|
| WordPress | http://localhost:8080/wp-admin | admin / admin        |
| Demo page | http://localhost:8080/book/  |                        |
| Mailpit   | http://localhost:8025        | catches all email      |
| Cap       | http://localhost:3000        | admin key in compose file |

The ports can be changed with `WP_PORT`, `MAILPIT_PORT` and `CAP_PORT`, e.g. `WP_PORT=8180 docker compose -f .devcontainer/docker-compose.yml up -d`. Run `setup.sh` with the same `WP_PORT` so WordPress gets the right address.

By default, a dev-only mu-plugin (`.devcontainer/mu-plugins/dev-google-mock.php`) fakes the Google API, so everything works without credentials:

- Everyone is busy 12:00–13:00. Anna is also busy 09:00–10:00 and Erik 15:00–16:00.
- 11 November is a fake public holiday.
- A refresh token ending in `|revoked` simulates revoked Google access, for testing connection alerts.

The seed script creates two employees with illustrated portraits (`.devcontainer/avatars/`), three booking pages (Anna, Erik, and both) and a Cap site key. The demo pages are `/book-anna/`, `/book-erik/` and `/book/` (both). To test against real Google, start with `NEMVO_BOOKING_GOOGLE_MOCK=0`. Google accepts `http://localhost` redirect URIs.

The plugin has no build step: the PHP, JavaScript and CSS in `nemvo-booking/` are the files that ship.

### Checks

```sh
./build.sh                                        # creates dist/nemvo-booking-<version>.zip
./smoke-test.sh dist/nemvo-booking-<version>.zip  # installs it on a clean WordPress (port 8087, SMOKE_PORT to change), checks every page loads, runs Plugin Check
```

Coding standards: `phpcs.xml.dist` holds the [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards) ruleset with PHPCompatibilityWP. Run `phpcs` (WPCS 3) from the repository root; it must report nothing.

GitHub Actions runs the PHP lint (PHP 7.4 to 8.4), the coding standards and [Plugin Check](https://wordpress.org/plugins/plugin-check/) on every push and pull request.

## Releasing

1. Every change gets a new version. Set it in `nemvo-booking/nemvo-booking.php` (the `Version:` header and `NEMVO_BOOKING_VERSION`) and in `nemvo-booking/readme.txt` (`Stable tag:`), add a changelog entry to both `readme.txt` and this README, and an Upgrade Notice to `readme.txt`. If strings changed, regenerate `nemvo-booking/languages/nemvo-booking.pot` (`wp i18n make-pot nemvo-booking nemvo-booking/languages/nemvo-booking.pot`). `build.sh` refuses to build when the three versions differ.
2. Run `./build.sh` and `./smoke-test.sh dist/nemvo-booking-<version>.zip`.
3. Commit, then tag and push:

   ```sh
   git tag -a v1.2.0 -m "Nemvo Booking 1.2.0"
   git push origin main v1.2.0
   ```

The [release workflow](.github/workflows/release.yml) builds the zip, creates the GitHub release with the zip attached, and, once WordPress.org deployment is switched on, publishes the version to the plugin directory. See [PUBLISHING.md](PUBLISHING.md).

## Code layout

```
nemvo-booking/
  nemvo-booking.php          bootstrap
  readme.txt                 WordPress.org readme
  uninstall.php              removes all data, if enabled in Settings (every site on multisite)
  languages/                 translation template (nemvo-booking.pot)
  includes/
    class-google.php         OAuth + Calendar API (no SDK)
    class-availability.php   slot calculation
    class-bookings.php       create / reschedule / cancel, templates
    class-mailer.php         wp_mail + iCalendar invites
    class-protection.php     spam checks + Cap
    class-privacy.php        GDPR: consent, retention, export/erase
    class-rest.php           public REST API (nemvo-booking/v1)
    class-frontend.php       shortcode, block, standalone cancel page
    admin/                   wp-admin screens
  assets/                    widget and admin JS/CSS (no build step)
    vendor/cap/              bundled Cap widget (see its README.txt)
.wordpress-org/              icons, banners and screenshots for the plugin directory
.devcontainer/               local development environment
```

## Changelog

### 1.2.3

- Bundled Cap widget updated to 0.1.63 (adds a JavaScript solver for browsers without WebAssembly). The WASM files and pako are unchanged.
- The Cap relay passes the widget's `?wasm=0` on to the Cap server's challenge endpoint, so browsers without WebAssembly can get JavaScript-solvable puzzles from a Cap server that offers them (capd 4.0.15 still answers with WASM-only puzzles).
- The widget's "Troubleshoot" link gets a fixed `data-cap-troubleshooting-url`, so it no longer carries the page address, title, referrer and browser details (visible and invisible mode).
- `CAP_ISOLATE` is set, so the instrumentation frame keeps an opaque origin as with 0.1.58 (the widget now shares the page's origin by default). Site keys that block automated interactions, score behavior with blocking on, or block AI agents answer `instr_isolated`.

### 1.2.2

- Booking pages are now the post type `nemvo_booking_page` (was `nb_booking_page`). Existing pages are moved automatically on update (database version 4) and keep their IDs, so blocks and shortcodes keep working.
- Booker links now use `nemvo_booking_cancel` and `nemvo_booking_reschedule`; the old `nb_cancel` and `nb_reschedule` links keep working.
- wp-admin form fields, notices, meta boxes and list columns use the `nemvo_booking` prefix (`nb-*` CSS classes and `--nb-*` tokens are unchanged).
- The Google Client secret and the Cap secret key are stored encrypted and no longer printed into the settings form.
- Removed the "Widget script URL" Cap setting; the bundled widget is always used. The widget no longer adds the page address to its credit link (`CAP_DISABLE_WIDGET_REF`), and the Cap relay is rate-limited.
- New filter `nemvo_booking_capability`.
- wp-admin shows a bundled placeholder instead of Gravatar when avatars are off.
- REST API parameters are validated and sanitized per route; `nemvo_booking_client_ip` values must be IP addresses.
- Uninstall (when enabled) revokes Google access and handles multisite.
- Block editor translations, a `.pot` file, full license texts for the bundled Cap files (including HashWX, LGPL 3.0), WordPress Coding Standards clean.

### 1.2.1

- Cap: the server address is now the one WordPress uses, and a public address is optional. Without one, visitors' browsers talk to Cap through WordPress (`POST /wp-json/nemvo-booking/v1/cap/challenge` and `…/cap/redeem`), so Cap can stay on an internal network. Existing settings are carried over unchanged.
- Cap: new "Test connection" button that checks the server address and keys before you save.

### 1.2.0

- First public release.

## License

MIT, see [nemvo-booking/LICENSE](nemvo-booking/LICENSE). The bundled Cap widget and solver (Apache License 2.0), HashWX (GNU LGPL 3.0) and pako (MIT and Zlib) keep their own licenses, in [nemvo-booking/assets/vendor/cap/](nemvo-booking/assets/vendor/cap/).
