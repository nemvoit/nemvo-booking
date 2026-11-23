# Publishing to WordPress.org

How Nemvo Booking gets into the WordPress.org plugin directory, and how new versions reach it afterwards.

## 1. Before submitting

- **WordPress.org account.** The account that submits becomes the plugin's owner. `Contributors:` in `nemvo-booking/readme.txt` must list WordPress.org usernames (currently `fpoller`).
- **Two-factor authentication.** WordPress.org requires 2FA for accounts with commit access. Turn it on under *Edit profile → Account & Security*.
- **Version.** Every change gets a new patch version, also while the plugin is in review (see *Later releases* for where the version lives). The current version is 1.2.5.
- **Checks.** Build the zip and run the smoke test. The smoke test includes Plugin Check, the same tool the review team runs first:

  ```sh
  ./build.sh
  ./smoke-test.sh dist/nemvo-booking-1.2.5.zip
  ```

  Optionally, paste `readme.txt` into the [readme validator](https://wordpress.org/plugins/developers/readme-validator/).

Plugin Check reports no errors and no warnings, and the code passes the WordPress Coding Standards (`phpcs.xml.dist`). Direct database queries on the plugin's own tables (bookings, employees, log) are annotated with the reason.

## 2. Submit

1. Sign in at [wordpress.org/plugins/developers/add](https://wordpress.org/plugins/developers/add/).
2. Upload `dist/nemvo-booking-<version>.zip` (the current version) and accept the guidelines.
3. The slug is taken from the plugin name: `nemvo-booking`. It shows on the confirmation page. You can still ask the reviewers to change it, but once the plugin is approved it is permanent.

An automated scan runs right away. Then the plugin waits in the review queue; the page shows how many plugins are ahead of it. Reviews take anywhere from a few days to several weeks.

## 3. During review

The review team emails you from `plugins@wordpress.org`. Reply to that email, and don't open a new thread. If they ask for changes:

1. Make the changes and bump the version, as for any change (steps 1–3 of *Later releases*): every change gets a new patch version, also during review, with a changelog entry.
2. Run PHPCS, `./build.sh` and `./smoke-test.sh dist/nemvo-booking-<version>.zip` again.
3. Upload the new zip on the same [Add your plugin](https://wordpress.org/plugins/developers/add/) page, then reply to the email saying what you changed.

Reviewers often ask about the following. The answers are already in `readme.txt` or the code:

| Topic | Where it's answered |
|---|---|
| Calls to external services (Google, Gravatar, Cap, trycap.dev) | *External services* section: every request, when it happens (including the daily WP-Cron check and uninstall) and what it sends, with links to the terms and privacy policies. Gravatar is only used when avatars are on under Settings → Discussion, also in wp-admin. |
| Cap's instrumentation script | Sent by the site owner's own Cap server and run by the widget in a sandboxed frame with an opaque origin (`CAP_ISOLATE`, set in `Protection::enqueue_cap()`), so it can't read the page; described under *External services*. |
| Minified or compiled files (Cap widget, WASM, pako) | *Third-party code* section and `assets/vendor/cap/README.txt`: versions, exact upstream commits for the readable source (Cap widget 0.1.63, WASM 0.0.8 including HashWX), full license texts. |
| jsDelivr addresses in `cap.min.js` | Fallbacks inside the unmodified widget; never used, because the plugin sets `CAP_CUSTOM_WASM_URL`, `CAP_CUSTOM_HASHWX_URL` and `CAP_PAKO_URL` to the bundled files (`Protection::enqueue_cap()`). |
| Licenses | MIT for the plugin. Apache 2.0 (Cap), LGPL 3.0 (HashWX) and MIT/Zlib (pako) for the bundled files, all GPL-compatible. |
| "Google" in the name or tags | Google only appears in tags and descriptions, not in the plugin name or slug |
| Public REST routes (`__return_true`) | The widget's routes are public by design (visitors aren't logged in): free times, booking, cancel and reschedule with the booker's secret token, and the Cap relay. Each has a comment and declared, validated and sanitized arguments; booking, cancel, reschedule and the Cap relay are rate-limited per IP; the slots route is read-only (`class-rest.php`, `Protection::register_routes()`). There are no admin REST routes. |
| Prefixes | PHP namespace `NemvoBooking\`, options/transients/hooks/cron `nemvo_booking_*`, post type `nemvo_booking_page`, URL parameters `nemvo_booking_cancel`/`nemvo_booking_reschedule` (the old `nb_cancel`/`nb_reschedule` are still read so links in sent emails keep working), REST `nemvo-booking/v1`, handles `nemvo-booking-*`, JS globals `nemvoBookingAdmin`/`nemvoBookingBlock`, form fields `nemvo_booking[...]`. CSS classes and custom properties use `nb-`/`--nb-*`, scoped under `.nb-booking` and `.nb-admin`; they are a documented styling contract for themes, so they stay. |
| Storing secrets | Google tokens, the Google Client secret and the Cap secret key are encrypted (AES-256 + HMAC, key from the WordPress salts, `class-crypto.php`) and never printed into forms. |
| Uninstall | Opt-in ("Delete all … when the plugin is deleted"), per site on multisite; revokes the hosts' Google access first. |

## 4. After approval

You get Subversion (SVN) access to `https://plugins.svn.wordpress.org/nemvo-booking/`. SVN doesn't use your normal password: set an SVN password under [Edit profile → Account & Security](https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password).

The plugin goes live when its current version is committed to SVN. Use one of the two ways below, not both.

### Option A: GitHub Actions (recommended)

The release workflow (`.github/workflows/release.yml`) already contains a WordPress.org job, which only runs once you switch it on. In the repository on GitHub:

1. **Settings → Secrets and variables → Actions → Secrets:** add `SVN_USERNAME` (your WordPress.org username) and `SVN_PASSWORD` (the SVN password).
2. **Settings → Secrets and variables → Actions → Variables:** add `WPORG_DEPLOY` with the value `true`.
3. **Actions → Release:** open the run for the tag of the approved version (for example `v1.2.3`), or push that tag now, and choose **Re-run all jobs**.

The job copies `nemvo-booking/` to `trunk/`, creates `tags/<version>` and uploads `.wordpress-org/` (icons, banners, screenshots) to `assets/`.

### Option B: by hand with SVN

```sh
svn checkout https://plugins.svn.wordpress.org/nemvo-booking wporg && cd wporg
rsync -a --delete ../nemvo-booking/ trunk/
mkdir -p assets && cp ../.wordpress-org/* assets/
svn add --force trunk assets
svn cp trunk tags/1.2.3
svn propset svn:mime-type image/png assets/*.png
svn propset svn:mime-type image/svg+xml assets/*.svg
svn commit -m "Nemvo Booking 1.2.3" --username <wordpress.org username>
```

The listing at `https://wordpress.org/plugins/nemvo-booking/` updates within a few minutes. Search can take a few hours to pick it up.

## 5. Later releases

Every change gets a new patch version (Fredrik's rule; also while WordPress.org reviews a submission).

1. Set the new version in `nemvo-booking/nemvo-booking.php` (header and `NEMVO_BOOKING_VERSION`) and in `readme.txt` (`Stable tag:`). Add a changelog entry and an Upgrade Notice to `readme.txt`, and a changelog entry to `README.md`. `build.sh` refuses to build when these differ.
2. If the database schema or stored data changed, raise `Install::DB_VERSION` and add the step to `Install::maybe_upgrade()`.
3. If strings changed, regenerate `languages/nemvo-booking.pot`:
   `docker compose -f .devcontainer/docker-compose.yml exec -T wordpress bash -c 'cd /workspace && wp --allow-root i18n make-pot nemvo-booking nemvo-booking/languages/nemvo-booking.pot --exclude=assets/vendor'`
4. Run PHPCS (`phpcs --standard=phpcs.xml.dist`, must report nothing), `./build.sh` and `./smoke-test.sh dist/nemvo-booking-<version>.zip` (it must end with `SMOKE TEST PASSED`, Plugin Check without errors or warnings). For CSS or markup changes, also compare screenshots before and after.
5. Commit and push `main`. Tag only what should become a public release (a tag builds the GitHub release, and with `WPORG_DEPLOY` on, the WordPress.org release):

   ```sh
   git tag -a v<version> -m "Nemvo Booking <version>"
   git push origin main v<version>
   ```

The release workflow creates the GitHub release with the zip, and, with `WPORG_DEPLOY` on, commits the version to WordPress.org. Sites that have the plugin installed are then offered the update in wp-admin.

When a new WordPress version comes out, test the plugin with it and raise `Tested up to:` in `readme.txt`. Release this as a normal patch version, or commit only `readme.txt` to SVN `trunk/` and `tags/<current version>/`.

## Assets

`.wordpress-org/` holds what the directory shows besides the readme:

| File | Size | Shown as |
|---|---|---|
| `icon.svg`, `icon-256x256.png`, `icon-128x128.png` | 256 and 128 px | Plugin icon |
| `banner-1544x500.png`, `banner-772x250.png` | | Header banner |
| `screenshot-1.png` … `screenshot-8.png` | | Screenshots, captioned by `== Screenshots ==` in `readme.txt` |

To change them, replace the files and keep the names. The screenshots were taken in the dev environment with the seeded demo data.
