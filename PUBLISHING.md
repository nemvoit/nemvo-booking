# Publishing to WordPress.org

How Nemvo Booking gets into the WordPress.org plugin directory, and how new versions reach it afterwards.

## 1. Before submitting

- **WordPress.org account.** The account that submits becomes the plugin's owner. `Contributors:` in `nemvo-booking/readme.txt` must list WordPress.org usernames (currently `fpoller`).
- **Two-factor authentication.** WordPress.org requires 2FA for accounts with commit access. Turn it on under *Edit profile → Account & Security*.
- **Checks.** Build the zip and run the smoke test. The smoke test includes Plugin Check, the same tool the review team runs first:

  ```sh
  ./build.sh
  ./smoke-test.sh dist/nemvo-booking-1.2.0.zip
  ```

  Optionally, paste `readme.txt` into the [readme validator](https://wordpress.org/plugins/developers/readme-validator/).

Plugin Check reports no errors. Its remaining warnings are about direct database queries, which the plugin needs for its own tables (bookings, employees, log). Warnings don't stop a submission.

## 2. Submit

1. Sign in at [wordpress.org/plugins/developers/add](https://wordpress.org/plugins/developers/add/).
2. Upload `dist/nemvo-booking-1.2.0.zip` and accept the guidelines.
3. The slug is taken from the plugin name: `nemvo-booking`. It shows on the confirmation page. You can still ask the reviewers to change it, but once the plugin is approved it is permanent.

An automated scan runs right away. Then the plugin waits in the review queue; the page shows how many plugins are ahead of it. Reviews take anywhere from a few days to several weeks.

## 3. During review

The review team emails you from `plugins@wordpress.org`. Reply to that email, and don't open a new thread. If they ask for changes:

1. Make the changes. Keep the version at 1.2.0, because nothing has been published yet.
2. Run `./build.sh` and `./smoke-test.sh` again.
3. Upload the new zip on the same [Add your plugin](https://wordpress.org/plugins/developers/add/) page, then reply to the email saying what you changed.

Reviewers often ask about the following. The answers are already in `readme.txt`:

| Topic | Where it's answered |
|---|---|
| Calls to external services (Google, Gravatar, Cap) | *External services* section |
| Minified or compiled files (the Cap widget, WASM, pako) | *Third-party code* section and `assets/vendor/cap/README.txt` |
| Licenses | MIT for the plugin. Apache 2.0 and MIT/Zlib for the bundled files, which are GPL-compatible. |
| "Google" in the name or tags | Google only appears in tags and descriptions, not in the plugin name or slug |

## 4. After approval

You get Subversion (SVN) access to `https://plugins.svn.wordpress.org/nemvo-booking/`. SVN doesn't use your normal password: set an SVN password under [Edit profile → Account & Security](https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password).

The plugin goes live when version 1.2.0 is committed to SVN. Use one of the two ways below, not both.

### Option A: GitHub Actions (recommended)

The release workflow (`.github/workflows/release.yml`) already contains a WordPress.org job, which only runs once you switch it on. In the repository on GitHub:

1. **Settings → Secrets and variables → Actions → Secrets:** add `SVN_USERNAME` (your WordPress.org username) and `SVN_PASSWORD` (the SVN password).
2. **Settings → Secrets and variables → Actions → Variables:** add `WPORG_DEPLOY` with the value `true`.
3. **Actions → Release:** open the run for `v1.2.0` and choose **Re-run all jobs**.

The job copies `nemvo-booking/` to `trunk/`, creates `tags/1.2.0` and uploads `.wordpress-org/` (icons, banners, screenshots) to `assets/`.

### Option B: by hand with SVN

```sh
svn checkout https://plugins.svn.wordpress.org/nemvo-booking wporg && cd wporg
rsync -a --delete ../nemvo-booking/ trunk/
mkdir -p assets && cp ../.wordpress-org/* assets/
svn add --force trunk assets
svn cp trunk tags/1.2.0
svn propset svn:mime-type image/png assets/*.png
svn propset svn:mime-type image/svg+xml assets/*.svg
svn commit -m "Nemvo Booking 1.2.0" --username <wordpress.org username>
```

The listing at `https://wordpress.org/plugins/nemvo-booking/` updates within a few minutes. Search can take a few hours to pick it up.

## 5. Later releases

1. Set the new version in `nemvo-booking/nemvo-booking.php` (header and `NEMVO_BOOKING_VERSION`) and in `readme.txt` (`Stable tag:`). Add a changelog entry to `readme.txt` and `README.md`.
2. Run `./build.sh` and `./smoke-test.sh dist/nemvo-booking-<version>.zip`.
3. Commit, tag and push:

   ```sh
   git tag -a v1.2.1 -m "Nemvo Booking 1.2.1"
   git push origin main v1.2.1
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
