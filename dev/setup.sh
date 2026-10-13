#!/bin/sh
# One-time setup of the local site: install WordPress, activate the plugin and create a
# sample gym timetable with pages. Run from anywhere: sh dev/setup.sh
set -e
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1 # Git Bash on Windows: don't rewrite container paths.

wp() { docker compose run --rm -T cli wp "$@"; }

# Wait for the web container to copy WordPress into the shared volume.
i=0
until docker compose exec -T wordpress test -f /var/www/html/wp-includes/version.php 2>/dev/null; do
  i=$((i + 1)); [ "$i" -gt 60 ] && { echo "WordPress files did not appear"; exit 1; }
  sleep 2
done

# Strong random admin password, kept in the git-ignored dev/.data/admin-password.txt.
mkdir -p .data
PASSFILE=.data/admin-password.txt
[ -s "$PASSFILE" ] || node -e "process.stdout.write(require('crypto').randomBytes(18).toString('base64url'))" > "$PASSFILE"
ADMIN_PASS=$(cat "$PASSFILE")

if ! wp core is-installed >/dev/null 2>&1; then
  wp core install --url=http://localhost:8096 --title="Harbour Fitness" \
    --admin_user=admin --admin_password="$ADMIN_PASS" --admin_email=admin@example.com --skip-email
else
  wp user update admin --user_pass="$ADMIN_PASS" --skip-email >/dev/null
fi
wp rewrite structure '/%postname%/' --hard >/dev/null
wp plugin activate uaip-simple-timetable
wp eval-file /uaip-dev/sample-data.php
echo "Site ready: http://localhost:8096/timetable/  (user: admin, password in dev/.data/admin-password.txt)"
