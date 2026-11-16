#!/usr/bin/env bash
# Installs WordPress, activates the plugin and (with the Google mock) creates demo data.
# Runs inside the wordpress container; safe to run repeatedly.
set -euo pipefail

if ! command -v wp >/dev/null 2>&1; then
	curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	chmod +x /usr/local/bin/wp
fi

wp() { runuser -u www-data -- /usr/local/bin/wp --path=/var/www/html "$@"; }

for _ in $(seq 1 60); do
	[ -f /var/www/html/wp-config.php ] && wp db check >/dev/null 2>&1 && break
	sleep 1
done

if ! wp core is-installed 2>/dev/null; then
	wp core install --url="http://localhost:${WP_PORT:-8080}" --title="Nemvo Booking Dev" \
		--admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
	wp rewrite structure '/%postname%/' --hard
	wp option update timezone_string Europe/Stockholm
fi

wp plugin activate nemvo-booking

if [ "${NEMVO_BOOKING_GOOGLE_MOCK:-0}" = "1" ]; then
	wp eval-file /workspace/.devcontainer/seed.php
fi

echo
echo "WordPress: http://localhost:${WP_PORT:-8080}/wp-admin  (admin / admin)"
echo "Mailpit:   http://localhost:${MAILPIT_PORT:-8025}"
echo "Cap:       http://localhost:${CAP_PORT:-3000}"
