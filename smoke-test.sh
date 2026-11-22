#!/usr/bin/env bash
# Installs a built zip on a clean WordPress (nothing configured: no Google, no Cap, no employees)
# and checks that wp-admin and the frontend load without PHP errors, that the public REST API
# never acts on a booking without its token, that data from older versions is migrated, that
# uninstall cleans up, and that Plugin Check reports no errors or warnings. Also lints on PHP 7.4.
# Usage: ./smoke-test.sh dist/nemvo-booking-x.y.z.zip   (SMOKE_PORT to use another port than 8087)
set -euo pipefail
cd "$(dirname "$0")"

zip_file=${1:?usage: $0 dist/nemvo-booking-x.y.z.zip}
project=nemvo-booking-smoke
port=${SMOKE_PORT:-8087}
url="http://localhost:$port"
work=$(mktemp -d)
trap 'docker compose -p $project -f "$work/compose.yml" down -v >/dev/null 2>&1; rm -rf "$work"' EXIT

fail=0
check() { # name, url, [cookie jar], [expected status]
	local body status
	body=$(curl -s -w '\n%{http_code}' ${3:+-b "$3"} "$2")
	status=${body##*$'\n'}
	if [ "$status" != "${4:-200}" ] || grep -qiE '(fatal error|warning|notice|deprecated)</b>:|critical error' <<<"$body"; then
		echo "FAIL $1 ($status)"; fail=1
	else
		echo "ok   $1"
	fi
}
expect() { # name, condition result ("1" = pass)
	if [ "$2" = 1 ]; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi
}
status() { # method, url, [json body] -> HTTP status
	curl -s -o /dev/null -w '%{http_code}' -X "$1" ${3:+-H 'Content-Type: application/json' -d "$3"} "$2"
}

echo "== PHP 7.4 lint"
lint=$(docker run --rm -v "$PWD/nemvo-booking":/src -w /src php:7.4-cli sh -c 'find . -name "*.php" -exec php -l {} \; | grep -v "^No syntax errors" || true')
if [ -n "$lint" ]; then echo "$lint"; echo "FAIL php 7.4 lint"; fail=1; else echo "ok   php 7.4 lint"; fi

cp "$zip_file" "$work/plugin.zip"
cat >"$work/compose.yml" <<YAML
services:
  db:
    image: mariadb:11
    environment: { MARIADB_DATABASE: wp, MARIADB_USER: wp, MARIADB_PASSWORD: wp, MARIADB_ROOT_PASSWORD: root }
    healthcheck: { test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"], interval: 3s, retries: 30 }
  wordpress:
    image: wordpress:php8.3-apache
    depends_on: { db: { condition: service_healthy } }
    ports: ["$port:80"]
    environment: { WORDPRESS_DB_HOST: db, WORDPRESS_DB_USER: wp, WORDPRESS_DB_PASSWORD: wp, WORDPRESS_DB_NAME: wp, WORDPRESS_DEBUG: "1" }
    volumes: ["$work/plugin.zip:/tmp/plugin.zip:ro"]
YAML

echo "== Starting a clean WordPress"
docker compose -p $project -f "$work/compose.yml" up -d >/dev/null 2>&1
dc() { docker compose -p $project -f "$work/compose.yml" exec -T wordpress "$@"; }
dc bash -c 'curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp'
wp() { dc runuser -u www-data -- wp --path=/var/www/html "$@"; }
for _ in $(seq 1 60); do wp db check >/dev/null 2>&1 && break; sleep 1; done
wp core install --url="$url" --title=Smoke --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email >/dev/null
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true

echo "== Installing $zip_file"
wp plugin install /tmp/plugin.zip --activate
page_id=$(wp eval '
$page = wp_insert_post( array( "post_type" => "nemvo_booking_page", "post_status" => "publish", "post_title" => "Smoke page" ) );
wp_insert_post( array( "post_type" => "page", "post_status" => "publish", "post_name" => "smoke", "post_title" => "Smoke", "post_content" => "[nemvo_booking id=\"$page\"]" ) );
echo $page;')
expect "database version 5 on a new install" "$([ "$(wp option get nemvo_booking_db_version)" = 5 ] && echo 1)"
expect "accounts table for extra Google accounts" "$(wp eval 'global $wpdb; echo $wpdb->get_var( "SHOW TABLES LIKE \"{$wpdb->prefix}nemvo_booking_accounts\"" ) ? 1 : 0;')"

jar="$work/cookies"
curl -s -c "$jar" -b "$jar" -o /dev/null -d "log=admin&pwd=admin&testcookie=1" -b "wordpress_test_cookie=WP%20Cookie%20check" "$url/wp-login.php"
check "frontend page" "$url/smoke/"
check "REST slots" "$url/wp-json/nemvo-booking/v1/pages/$page_id/slots?from=2026-01-05&to=2026-01-11"
for param in nemvo_booking_cancel nemvo_booking_reschedule nb_cancel nb_reschedule; do
	check "standalone page ?$param= (bad token)" "$url/?$param=nonexistenttoken1234567890"
done
for slug in nemvo-booking nemvo-booking-employees nemvo-booking-log nemvo-booking-settings; do
	check "admin $slug" "$url/wp-admin/admin.php?page=$slug" "$jar"
done
check "admin booking pages" "$url/wp-admin/edit.php?post_type=nemvo_booking_page" "$jar"
check "admin new booking page" "$url/wp-admin/post-new.php?post_type=nemvo_booking_page" "$jar"
check "admin edit booking page" "$url/wp-admin/post.php?post=$page_id&action=edit" "$jar"
legacy=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$jar" "$url/wp-admin/edit.php?post_type=nb_booking_page")
expect "old booking page list address redirects ($legacy)" "$(grep -q 'post_type=nemvo_booking_page' <<<"$legacy" && echo 1)"

echo "== REST API: public routes only, nothing happens without a valid token"
routes=$(curl -s "$url/wp-json/nemvo-booking/v1" | python3 -c 'import json,sys; print(" ".join(sorted(json.load(sys.stdin)["routes"])))')
expected="/nemvo-booking/v1 /nemvo-booking/v1/cancel /nemvo-booking/v1/cap/challenge /nemvo-booking/v1/cap/redeem /nemvo-booking/v1/pages/(?P<id>\\d+)/bookings /nemvo-booking/v1/pages/(?P<id>\\d+)/slots /nemvo-booking/v1/reschedule"
expect "only the widget's public routes exist" "$([ "$routes" = "$expected" ] && echo 1)"
token=smoketoken0123456789abcdefghijklmnopqrst
wp eval '
global $wpdb;
$start = time() + 7 * DAY_IN_SECONDS;
$wpdb->insert( $wpdb->prefix . "nemvo_booking_bookings", array( "page_id" => '"$page_id"', "status" => "confirmed", "start_utc" => gmdate( "Y-m-d H:i:s", $start ), "end_utc" => gmdate( "Y-m-d H:i:s", $start + 1800 ), "duration" => 30, "timezone" => "UTC", "name" => "Smoke", "email" => "smoke@example.com", "token" => "'"$token"'", "created_at" => gmdate( "Y-m-d H:i:s" ) ) );'
api="$url/wp-json/nemvo-booking/v1"
codes="$(status GET "$api/cancel") $(status GET "$api/cancel?token=") $(status GET "$api/cancel?token=wrongtoken0123456789abcdefghijklmnop")"
codes="$codes $(status POST "$api/cancel" '{}') $(status POST "$api/cancel" '{"token":""}') $(status POST "$api/cancel" '{"token":"short"}') $(status POST "$api/cancel" '{"token":"wrongtoken0123456789abcdefghijklmnop"}') $(status POST "$api/cancel" '{"token":["'"$token"'"]}')"
codes="$codes $(status GET "$api/reschedule?token=wrongtoken0123456789abcdefghijklmnop") $(status POST "$api/reschedule" '{"start":1}') $(status POST "$api/reschedule" '{"token":"wrongtoken0123456789abcdefghijklmnop","start":1999999999}')"
expect "cancel and reschedule refuse missing, malformed and wrong tokens ($codes)" "$(grep -qE '^ *(4(00|04) *)+$' <<<"$codes" && echo 1)"
left=$(wp eval 'global $wpdb; echo $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}nemvo_booking_bookings WHERE email = \"smoke@example.com\"" );')
expect "the booking is untouched ($left)" "$([ "$left" = confirmed ] && echo 1)"
expect "the right token finds the booking (GET /cancel)" "$([ "$(status GET "$api/cancel?token=$token")" = 200 ] && echo 1)"
expect "booking without a valid page or time is refused ($(status POST "$api/pages/999999/bookings" '{"start":1}'))" "$(grep -qE '^4' <<<"$(status POST "$api/pages/999999/bookings" '{"start":1}')" && echo 1)"
expect "Cap relay is off when Cap isn't set up" "$([ "$(status POST "$api/cap/challenge" '{}')" = 404 ] && echo 1)"

echo "== Upgrade from 1.2.1 data"
legacy_page=$(wp eval '
echo wp_insert_post( array( "post_type" => "nb_booking_page", "post_status" => "publish", "post_title" => "Legacy page" ) );
$s = get_option( "nemvo_booking_settings", array() );
$s["google_client_id"]     = "123-smoke.apps.googleusercontent.com";
$s["google_client_secret"] = "GOCSPX-plain-secret";
$s["cap_script_url"]       = "https://cap.example.com/assets/widget.js";
remove_all_filters( "pre_update_option_nemvo_booking_settings" );
update_option( "nemvo_booking_settings", $s );
update_option( "nemvo_booking_db_version", "3" );')
curl -s -o /dev/null "$url/smoke/"
after=$(wp eval '$s = get_option( "nemvo_booking_settings" ); echo get_post_type( '"$legacy_page"' ), "|", get_option( "nemvo_booking_db_version" ), "|", 0 === strpos( $s["google_client_secret"], "v1:" ) ? "encrypted" : "plain", "|", NemvoBooking\Settings::get( "google_client_secret" ), "|", isset( $s["cap_script_url"] ) ? "kept" : "dropped";')
expect "booking page moved to nemvo_booking_page, secret encrypted, old option dropped ($after)" "$([ "$after" = 'nemvo_booking_page|5|encrypted|GOCSPX-plain-secret|dropped' ] && echo 1)"

echo "== Upgrade from 1.2.3 (database version 4)"
wp eval 'global $wpdb; $wpdb->query( "DROP TABLE {$wpdb->prefix}nemvo_booking_accounts" ); update_option( "nemvo_booking_db_version", 4 );'
curl -s -o /dev/null "$url/smoke/"
after=$(wp eval 'global $wpdb; echo get_option( "nemvo_booking_db_version" ), "|", $wpdb->get_var( "SHOW TABLES LIKE \"{$wpdb->prefix}nemvo_booking_accounts\"" ) ? "accounts" : "missing", "|", get_post_type( '"$legacy_page"' );')
expect "accounts table added, nothing else moved ($after)" "$([ "$after" = '5|accounts|nemvo_booking_page' ] && echo 1)"

echo "== Plugin Check (errors and warnings)"
wp plugin install plugin-check --activate >/dev/null 2>&1
pcp=$(wp plugin check nemvo-booking --format=csv 2>&1 || true)
if grep -q 'No errors found' <<<"$pcp"; then
	echo "ok   plugin check (no errors or warnings)"
else
	echo "$pcp"; echo "FAIL plugin check"; fail=1
fi

echo "== REST slots answer (expect bookable:false):"
curl -s "$api/pages/$page_id/slots?from=2026-01-05&to=2026-01-11"; echo

echo "== Uninstall"
wp eval '$s = get_option( "nemvo_booking_settings" ); $s["delete_on_uninstall"] = 1; update_option( "nemvo_booking_settings", $s );'
wp plugin deactivate nemvo-booking >/dev/null && wp plugin uninstall nemvo-booking >/dev/null
left=$(wp eval 'global $wpdb; echo count( $wpdb->get_col( "SHOW TABLES LIKE \"{$wpdb->prefix}nemvo_booking%\"" ) ) + count( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"%nemvo\\_booking%\"" ) ) + count( get_posts( array( "post_type" => array( "nemvo_booking_page", "nb_booking_page" ), "post_status" => "any" ) ) ) + (int) (bool) wp_next_scheduled( "nemvo_booking_daily" );')
expect "uninstall removes tables, options, booking pages and cron ($left left)" "$([ "$left" = 0 ] && echo 1)"

[ $fail = 0 ] && echo "SMOKE TEST PASSED" || { echo "SMOKE TEST FAILED"; exit 1; }
