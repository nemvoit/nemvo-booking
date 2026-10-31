#!/usr/bin/env bash
# Builds an installable plugin zip: dist/nemvo-booking-<version>.zip
set -euo pipefail
cd "$(dirname "$0")"

version=$(sed -n 's/^ \* Version: *//p' nemvo-booking/nemvo-booking.php)
constant=$(sed -n "s/.*NEMVO_BOOKING_VERSION', '\([^']*\)'.*/\1/p" nemvo-booking/nemvo-booking.php)
stable=$(sed -n 's/^Stable tag: *//p' nemvo-booking/readme.txt)
if [ "$version" != "$constant" ] || [ "$version" != "$stable" ]; then
	echo "Version mismatch: header $version, constant $constant, readme.txt Stable tag $stable" >&2
	exit 1
fi
if ! grep -q "^= $version =" nemvo-booking/readme.txt; then
	echo "readme.txt has no changelog entry for $version" >&2
	exit 1
fi

mkdir -p dist
zip_file="dist/nemvo-booking-$version.zip"
rm -f "$zip_file"
zip -qr "$zip_file" nemvo-booking -x '*.DS_Store' -x '*/.git*'
echo "$zip_file"
