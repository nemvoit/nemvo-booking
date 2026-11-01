=== Nemvo Booking ===
Contributors: fpoller
Tags: booking, appointments, scheduling, google calendar, google meet
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
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

The calendar adapts to the space it gets, from a phone to a full-width section, in any theme. Its styles are scoped, so theme button and heading styles don't change it. Colors, fonts, sizes and corners can be changed from your theme with CSS custom properties. The [Styling guide](https://github.com/fpoller/nemvo-booking#styling) lists them all.

Booking pages include tips for GeneratePress and GenerateBlocks when those are active.

= External services =

This plugin connects to the following services. It sends no data to its author.

**Google Calendar API and Google OAuth** (Google LLC)

The plugin uses your own Google Cloud OAuth client to work with your hosts' Google Calendars:

* When a host connects their calendar, WordPress redirects them to Google to sign in, then exchanges the code from Google for access tokens. The tokens are stored encrypted in your database.
* When a booking page loads available times, WordPress asks Google when the hosts are busy, for the dates shown. Google holiday calendars are read the same way.
* When someone books, reschedules or cancels, WordPress creates, moves or cancels the event in the organizing host's calendar. This sends the meeting time, the booker's name and email, guest emails and the form answers to Google. If you choose to have Google send the invitations, Google also emails the booker and guests.
* When a host is disconnected or deleted, WordPress asks Google to revoke the access token.

Google's [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy) and [API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy) apply.

**Gravatar** (Automattic Inc.)

A host without a photo chosen under Bookings → Employees can be shown with their Gravatar instead. This only happens when *Show the hosts' photos* is on for the booking page and avatars are switched on under Settings → Discussion. The visitor's browser then loads the image from Gravatar, which receives the visitor's IP address and a hash of the host's email address. See Automattic's [Terms of Service](https://wordpress.com/tos/) and [Privacy Policy](https://automattic.com/privacy/).

**Cap** (your own server, optional)

When Cap is switched on, the visitor's browser gets a proof-of-work puzzle from your own Cap server and solves it before booking. WordPress then checks the answer with that server. By default the browser only talks to your site, which passes the puzzle requests on to Cap (with the visitor's IP address, so Cap can limit repeated requests); if you enter a public Cap address, the browser talks to your Cap server directly. Nothing is sent anywhere else, and the Cap server is run by you, not by a third party.

= Third-party code =

The plugin includes these unmodified files, in `assets/vendor/cap/`:

* [Cap widget](https://github.com/tiagozip/cap) 0.1.58 (`@cap.js/widget`) and its WASM solvers 0.0.8 (`@cap.js/wasm`), Apache License 2.0.
* [pako](https://github.com/nodeca/pako) 2.1.0, `pako_inflate.min.js`, MIT and Zlib licenses. It is only loaded by browsers without `DecompressionStream`.

All other code is in the plugin in readable form, without a build step. Development happens on [GitHub](https://github.com/fpoller/nemvo-booking).

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

No `!important` is needed. The [Styling guide](https://github.com/fpoller/nemvo-booking#styling) lists every token and class name.

= How do I keep spam bots out? =

The honeypot, time-to-submit check, headless browser detection and rate limit are on by default. For more protection, run Cap on your own server. The guide is in **Bookings → Settings → Spam and abuse protection**.

= Which hooks are there? =

The actions `nemvo_booking_created`, `nemvo_booking_rescheduled`, `nemvo_booking_cancelled` and `nemvo_booking_standalone_head`, and the filters `nemvo_booking_client_ip` (for reading the visitor's IP address behind a proxy) and `nemvo_booking_google_auth_url`. The [README on GitHub](https://github.com/fpoller/nemvo-booking#hooks) lists their arguments.

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

= 1.2.1 =
* Cap: the server address is now the one WordPress uses, and a public address is optional. Without one, visitors' browsers talk to Cap through WordPress, so Cap can stay on an internal network. Existing settings are carried over unchanged.
* Cap: new "Test connection" button that checks the server address and keys before you save.

= 1.2.0 =
* First public release.
