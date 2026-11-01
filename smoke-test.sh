#!/usr/bin/env bash
# Installs a built zip on a clean WordPress (nothing configured: no Google, no Cap, no employees)
# and checks that wp-admin and the frontend load without PHP errors, then runs Plugin Check.
# Usage: ./smoke-test.sh dist/nemvo-booking-x.y.z.zip   (SMOKE_PORT=8197 if port 8090 is taken)
set -euo pipefail
cd "$(dirname "$0")"

zip_file=${1:?usage: $0 dist/nemvo-booking-x.y.z.zip}
project=nemvo-smoke
port=${SMOKE_PORT:-8090}
url="http://localhost:$port"
work=$(mktemp -d)
trap 'docker compose -p $project -f "$work/compose.yml" down -v >/dev/null 2>&1; rm -rf "$work"' EXIT

echo "== PHP 7.4 lint"
docker run --rm -v "$PWD/nemvo-booking":/src -w /src php:7.4-cli sh -c \
	'find . -name "*.php" -exec php -l {} \; | grep -v "^No syntax errors" || true'

cp "$zip_file" "$work/plugin.zip"
cat > "$work/compose.yml" <<YAML
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

echo "== Starting clean WordPress"
docker compose -p $project -f "$work/compose.yml" up -d >/dev/null 2>&1
dc() { docker compose -p $project -f "$work/compose.yml" exec -T wordpress "$@"; }
dc bash -c 'curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp'
wp() { dc runuser -u www-data -- wp --path=/var/www/html "$@"; }
for _ in $(seq 1 60); do wp db check >/dev/null 2>&1 && break; sleep 1; done
wp core install --url="$url" --title=Smoke --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email >/dev/null
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true

echo "== Installing $zip_file"
wp plugin install /tmp/plugin.zip --activate
wp eval '
$page = wp_insert_post( array( "post_type" => "nb_booking_page", "post_status" => "publish", "post_title" => "Smoke page" ) );
wp_insert_post( array( "post_type" => "page", "post_status" => "publish", "post_name" => "smoke", "post_title" => "Smoke", "post_content" => "[nemvo_booking id=\"$page\"]" ) );
echo "page $page\n";'

fail=0
check() { # name, url, [cookie jar]
	local body status
	body=$(curl -s -w '\n%{http_code}' ${3:+-b "$3"} "$2")
	status=${body##*$'\n'}
	if [ "$status" != 200 ] || grep -qiE '(fatal error|warning|notice|deprecated)</b>:|critical error' <<<"$body"; then
		echo "FAIL $1 ($status)"; fail=1
	else
		echo "ok   $1"
	fi
}

jar="$work/cookies"
curl -s -c "$jar" -b "$jar" -o /dev/null -d "log=admin&pwd=admin&testcookie=1" -b "wordpress_test_cookie=WP%20Cookie%20check" "$url/wp-login.php"
check "frontend page" "$url/smoke/"
check "REST slots" "$url/wp-json/nemvo-booking/v1/pages/$(wp post list --post_type=nb_booking_page --field=ID)/slots?from=2026-01-05&to=2026-01-11"
check "cancel link (bad token)" "$url/?nb_cancel=nonexistenttoken1234567890"
for page in nemvo-booking nemvo-booking-employees nemvo-booking-log nemvo-booking-settings; do
	check "admin $page" "$url/wp-admin/admin.php?page=$page" "$jar"
done
check "admin booking pages" "$url/wp-admin/edit.php?post_type=nb_booking_page" "$jar"
check "admin new booking page" "$url/wp-admin/post-new.php?post_type=nb_booking_page" "$jar"

echo "== Plugin Check (errors only)"
wp plugin install plugin-check --activate >/dev/null 2>&1
pcp=$(wp plugin check nemvo-booking --ignore-warnings --format=csv 2>&1 || true)
if grep -q 'No errors found' <<<"$pcp"; then
	echo "ok   plugin check"
else
	echo "$pcp"; echo "FAIL plugin check"; fail=1
fi

echo "== REST slots answer (expect bookable:false):"
curl -s "$url/wp-json/nemvo-booking/v1/pages/$(wp post list --post_type=nb_booking_page --field=ID)/slots?from=2026-01-05&to=2026-01-11"; echo

[ $fail = 0 ] && echo "SMOKE TEST PASSED" || { echo "SMOKE TEST FAILED"; exit 1; }
