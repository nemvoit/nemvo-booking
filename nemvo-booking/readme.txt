=== Nemvo Booking ===
Contributors: fpoller
Tags: booking, appointments, scheduling, google calendar, google meet
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.3
License: MIT
License URI: https://opensource.org/licenses/MIT

Visitors book meetings from your team's real Google Calendar availability. Every booking gets a Google Meet link and a calendar invitation.

== Description ==

Nemvo Booking adds booking pages to your site. Visitors pick a time that suits them and fill in a short form. The meeting goes straight into your Google Calendar with a Google Meet link, and everyone gets a calendar invitation.

Bookings live on your own site, with no booking service in between. The plugin only talks to Google, through your own Google Cloud project.

= Booking pages =

* Create as many booking pages as you like, each with its own hosts. For example, one page for you, one for a colleague and one that books you both.
* Every host attends, and a time is only offered when all of them are free.
* Meeting lengths, start-time interval, minimum notice, how far ahead people can book, and buffers before and after.
* Weekly opening hours with breaks, in the booking page's time zone.
* Closed on public holidays from one or more countries, plus any extra closed dates.
* Host photos at the top of the booking page (optional, on by default).
* Your own questions in the booking form, plus optional guests.
* Add a booking page with the **Booking page** block or the `[nemvo_booking id="123"]` shortcode.

= Google Calendar and Google Meet =

* Each host connects their own Google Calendar. An admin can connect it, or send the host a 7-day connect link. Hosts don't need a WordPress account.
* Only events marked *Busy* block a time, so free all-day events don't get in the way.
* Choose which calendars to check for conflicts and which calendar new meetings go into.
* Every meeting gets a Google Meet link.
* If a host's Google access stops working, you get one email with a reconnect link, and a warning shows in wp-admin until it's fixed. Connections are also checked daily.

= Invitations and emails =

* Choose who sends the calendar invitation: Google Calendar, or WordPress with an `.ics` file attached, which mail apps show with Accept and Decline buttons.
* Booking, reschedule and cancellation emails for bookers, guests and hosts. Each one has its own subject and text with placeholders, and can be switched off.
* A thank-you message after booking, or a redirect to a page of your choice.
* All email goes through `wp_mail()`, so SMTP plugins work as usual.

= Rescheduling and cancelling =

* The booker gets personal links to pick a new time or cancel. A moved meeting keeps its Google Meet link.
* Admins can reschedule or cancel from the booking in wp-admin.

= Privacy (GDPR) =

* Optional consent checkbox that links to your privacy policy. The time of consent is stored.
* Personal data is deleted automatically a set number of days after the meeting (365 by default).
* Bookings are included in WordPress's *Export Personal Data* and *Erase Personal Data* tools.
* Suggested text for your privacy policy.

= Spam protection =

* Honeypot field, a signed time-to-submit check, headless browser detection, a per-IP rate limit and a blocklist.
* Optional [Cap](https://trycap.dev), a proof-of-work CAPTCHA that you host yourself. The Cap widget comes with the plugin.

= Works with your theme =

The calendar adapts to the space it gets, from a phone to a full-width section, in any theme. Its styles are scoped, so theme button and heading styles don't change it. Colors, fonts, sizes and corners can be changed from your theme with CSS custom properties. The [Styling guide](https://github.com/nemvoit/nemvo-booking#styling) lists them all.

Booking pages include tips for GeneratePress and GenerateBlocks when those are active.

Development happens on [GitHub](https://github.com/nemvoit/nemvo-booking).

== External services ==

The plugin connects to the services below. It sends no data to its author.

= Google OAuth and Google Calendar API (Google LLC) =

Needed for everything the plugin does: availability, meetings and Google Meet links. It uses an OAuth client that you create in your own Google Cloud project.

* **Connecting a calendar:** a host (or an admin) is sent to Google's sign-in page (accounts.google.com). WordPress then exchanges the code from Google for tokens at oauth2.googleapis.com, sending the code, your Client ID and Client secret. The tokens are stored encrypted in your database. WordPress then reads the host's calendar list from www.googleapis.com.
* **Showing free times:** when a booking page loads its calendar, WordPress asks Google's free/busy API when the hosts are busy, for the dates shown. Selected public holiday calendars are read the same way, through a connected host's account, at most once a day per country and year.
* **Booking, rescheduling and cancelling:** WordPress creates, moves or deletes the event in the organizing host's calendar. This sends the meeting time, the booking page title, the booker's name and email, guest emails and the form answers to Google. When Google sends the invitations (the default), Google emails the booker, guests and other hosts.
* **Daily check:** once a day, WordPress asks Google's free/busy API about the next hour for every connected host who is on a booking page, so a broken connection is noticed.
* **Disconnecting:** when a host is disconnected or deleted, and when the plugin is deleted with "Delete all … when the plugin is deleted" ticked, WordPress asks Google (oauth2.googleapis.com/revoke) to revoke the host's token.
* Access tokens are refreshed at oauth2.googleapis.com when they expire, with the stored refresh token and your client credentials.

Google's [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy), [Google APIs Terms of Service](https://developers.google.com/terms) and [API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy) apply.

= Gravatar (Automattic Inc.) =

Only used when avatars are switched on under Settings → Discussion (WordPress's own setting).

* **On booking pages:** a host without a photo chosen under Bookings → Employees is shown with their Gravatar, if they have one, when *Show the hosts' photos* is on for the booking page. The visitor's browser then loads the image from gravatar.com, which receives the visitor's IP address and a hash of the host's email address.
* **In wp-admin:** the employee list, the employee screen and the host list of a booking page show the Gravatar of hosts without a chosen photo. The admin's browser loads it from gravatar.com. With avatars switched off, a placeholder that comes with the plugin is shown instead and nothing is loaded from Gravatar.

See Automattic's [Terms of Service](https://wordpress.com/tos/) and [Privacy Policy](https://automattic.com/privacy/).

= Cap (your own server, optional) =

Cap is a proof-of-work CAPTCHA that you run yourself; it is off until you set it up under Bookings → Settings. When it is on:

* The visitor's browser fetches a puzzle and redeems the solution. By default it does so through your site (REST API), and WordPress passes the requests on to your Cap server together with the visitor's IP address, so Cap can limit repeated requests. If you enter a public Cap address, the browser talks to your Cap server directly.
* With "instrumentation challenges" on for the site key (Cap's default), your Cap server also sends a small script that the widget runs in a sandboxed frame in the visitor's browser to check that it is a real browser. The frame is isolated from your page (it can't read the page, its cookies or storage), and its result goes back to your Cap server only. Because of this isolation, Cap site keys that block automated clicks and typing, score behavior with blocking on, or block AI agents reject solves with `instr_isolated`; leave those options off for the key you use here.
* When a booking is made, WordPress sends the widget's token and the secret key to your Cap server to verify it. "Test connection" and saving the settings send a test request the same way.

The Cap server is run by you, not by a third party, so nothing is sent anywhere else.

= trycap.dev (links in the Cap widget) =

The visible Cap widget shows a small "Cap" credit link to https://trycap.dev, and, when the browser check fails, a "Troubleshoot" link to Cap's documentation at https://trycap.dev/guide/troubleshooting/instrumentation.html. Nothing is loaded from or sent to trycap.dev unless a visitor clicks one of these links. The link then opens in a new tab, and the browser sends trycap.dev only what any link click sends: the visitor's IP address, browser details and, as referrer, your site's address. The plugin turns off the widget options that would add more: the page address and referrer on the credit link, and the page address, title, referrer and browser and screen details on the Troubleshoot link. trycap.dev is run by the Cap project; see the [Cap repository](https://github.com/tiagozip/cap) for its terms.

== Third-party code ==

The plugin includes these unmodified files in `assets/vendor/cap/`, with their license texts:

* **Cap widget** 0.1.63 (`@cap.js/widget`, `cap.min.js`), Apache License 2.0. Readable source: [widget/src at commit 18a9750](https://github.com/tiagozip/cap/tree/18a975028e2de21acc9b3f99209439d3f09093e8/widget/src) of tiagozip/cap, the "widget@0.1.63" release.
* **Cap WASM solver** 0.0.8 (`@cap.js/wasm`, `cap_wasm_bg.wasm`), Apache License 2.0. Rust source: [wasm/src/rust at commit 8ad83fb](https://github.com/tiagozip/cap/tree/8ad83fb50eaefb7f113ca1ec508ce242ed75033d/wasm/src/rust), the "wasm@0.0.8" release.
* **HashWX** (`hashwx.wasm`, shipped in `@cap.js/wasm` 0.0.8), GNU LGPL 3.0. C source: [tevador/hashwx at commit 74b567a](https://github.com/tevador/hashwx/tree/74b567a31276a4c5d7cb232a5b7639467f49f961), compiled to WebAssembly by the Cap project.
* **pako** 2.1.0 (`pako_inflate.min.js`), MIT and Zlib licenses. Source: [nodeca/pako 2.1.0](https://github.com/nodeca/pako/tree/2.1.0). Only loaded by browsers without `DecompressionStream`.

`cap.min.js` contains fallback addresses on cdn.jsdelivr.net for the WASM files and pako. They are never used: the plugin points the widget at the copies it includes before the widget loads.

All other code is in the plugin in readable form, without a build step.

== Installation ==

1. Install the plugin from **Plugins → Add New Plugin** and activate it. Or upload the zip under **Plugins → Add New Plugin → Upload Plugin**.
2. Go to **Bookings → Settings** and follow the step-by-step guide to connect the site to Google. You create a free OAuth client in Google Cloud and paste in its Client ID and secret. It takes about ten minutes, and you only do it once.
3. Under **Bookings → Employees**, add the people visitors can book and connect their Google Calendars.
4. Under **Bookings → Booking pages**, create a booking page and choose its hosts, meeting lengths and opening hours.
5. Edit a page, add the **Booking page** block and pick your booking page. In the classic editor, page builders and widgets, use the shortcode shown on the booking page instead.

The site needs HTTPS, because Google only accepts https:// redirect addresses (`http://localhost` also works, for testing).

== Frequently Asked Questions ==

= Do I need Google Workspace? =

No. Personal Gmail accounts work too. With Google Workspace you can make the Google Cloud app *Internal*, so it needs no review by Google and access never expires. The setup guide in Settings explains both options.

= Does it work without Google? =

No. Available times come from the hosts' Google Calendars, and the meeting is created there with a Google Meet link.

= Is any data sent to the plugin's author? =

No. Bookings, settings and tokens stay in your WordPress database, and the plugin only talks to the services listed under *External services*.

= Can visitors cancel or move a meeting? =

Yes. The confirmation email contains personal links to cancel or pick a new time. Rescheduling can be switched off per booking page. Cancelling is possible until the meeting starts.

= Where do the host photos come from? =

From the photo chosen for each host under **Bookings → Employees**. Without one, the host's Gravatar is used, if avatars are switched on under Settings → Discussion. To hide the photos on a booking page, untick *Show the hosts' photos* on that booking page.

= How do I change the colors and fonts? =

Choose an accent color under **Bookings → Settings → Appearance**. For more, add a rule like this to your theme, or to **Appearance → Customize → Additional CSS**:

`.nb-booking { --nb-accent: #0b5cad; --nb-font: "Inter", sans-serif; --nb-radius: 4px; }`

No `!important` is needed. The [Styling guide](https://github.com/nemvoit/nemvo-booking#styling) lists every token and class name.

= How do I keep spam bots out? =

The honeypot, time-to-submit check, headless browser detection and rate limit are on by default. For more protection, run Cap on your own server. The guide is in **Bookings → Settings → Spam and abuse protection**.

= Which hooks are there? =

The actions `nemvo_booking_created`, `nemvo_booking_rescheduled`, `nemvo_booking_cancelled` and `nemvo_booking_standalone_head`, and the filters `nemvo_booking_client_ip` (for reading the visitor's IP address behind a proxy), `nemvo_booking_capability` (who may manage bookings; `manage_options` by default) and `nemvo_booking_google_auth_url`. The [README on GitHub](https://github.com/nemvoit/nemvo-booking#hooks) lists their arguments.

== Screenshots ==

1. A booking page with two hosts, side by side on a wide screen.
2. The booking form after picking a time.
3. The confirmation, with a button to join the Google Meet.
4. The same booking page on a phone.
5. Editing a booking page: hosts, meeting lengths and booking window.
6. An employee with their Google Calendar connected.
7. The step-by-step Google setup guide in Settings.
8. Upcoming bookings in wp-admin.

== Changelog ==

= 1.2.3 =
* Updated the bundled Cap widget to 0.1.63. In browsers without WebAssembly it asks the Cap server for puzzles it can solve in JavaScript; this needs a Cap server version that offers them, and the plugin's relay passes the request on.
* The Cap widget's "Troubleshoot" link is now a plain link to Cap's documentation: the plugin stops the widget from adding the page address and the visitor's browser details to it.
* Cap's browser check keeps running isolated from your page, as before. Cap site keys that block automated clicks and typing, score behavior with blocking on, or block AI agents can't be used with the plugin.

= 1.2.2 =
* Booking pages are now stored as the post type `nemvo_booking_page` (was `nb_booking_page`). Existing booking pages are moved automatically when you update; their IDs stay the same, so blocks and shortcodes keep working.
* Cancel and reschedule links now use `?nemvo_booking_cancel=` and `?nemvo_booking_reschedule=`. Links in emails sent before the update (`?nb_cancel=`, `?nb_reschedule=`) keep working.
* The Google Client secret and the Cap secret key are stored encrypted, and are no longer shown in the settings form. Leave the field empty to keep the saved value.
* Removed the "Widget script URL" Cap setting: the Cap widget that comes with the plugin is always used.
* The Cap widget no longer adds the page address to its credit link, and the Cap relay is rate-limited per IP address.
* Without avatars (Settings → Discussion), wp-admin shows a placeholder for hosts without a photo instead of loading Gravatar.
* New filter `nemvo_booking_capability` to choose who may manage bookings.
* Uninstalling with "delete all data" ticked also revokes the hosts' Google access, and works on multisite.
* Stricter checks of REST API parameters, translations for the block editor, and code cleanup.

= 1.2.1 =
* Cap: the server address is now the one WordPress uses, and a public address is optional. Without one, visitors' browsers talk to Cap through WordPress, so Cap can stay on an internal network. Existing settings are carried over unchanged.
* Cap: new "Test connection" button that checks the server address and keys before you save.

= 1.2.0 =
* First public release.

== Upgrade Notice ==

= 1.2.3 =
Cap widget 0.1.63. Its Troubleshoot link no longer carries page or browser details.

= 1.2.2 =
Booking pages and booker links get new internal names; existing pages and links in emails already sent keep working. Saved secrets are encrypted on update.

= 1.2.1 =
Cap can stay on an internal network: visitors' browsers reach it through WordPress.
