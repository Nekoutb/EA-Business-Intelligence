#!/usr/bin/env bash
# Deploy one NON-production BMS instance (dev or staging) on server 6.
#
#   bms-env.sh <dev|staging> <git sha> <unpacked bundle dir>
#
# Run by .github/workflows/deploy-env.yml over SSH, as root. The bundle is
# built on the GitHub runner (vendor/ and public/build included), so this
# server only unpacks, migrates and switches: no composer, no npm, no apt.
#
# Server 6 also runs production BMS and about 15 other sites, so this
# script only ever writes to:
#   /var/www/eams-<env>/                      releases, shared .env, storage
#   database bms_<env> and MySQL user bms_<env>
#   /etc/apache2/sites-available/<domain>*.conf and its certificate
#   /root/bms-<env>-logins.txt                demo logins (root only)
# It refuses "prod", never touches cron, and ends by proving production's
# vhost, .env and root crontab are byte-for-byte what they were.
set -euo pipefail

ENV_NAME="${1:-}"; SHA="${2:-}"; SRC="${3:-}"

case "$ENV_NAME" in
  dev)     DOMAIN="dev-bms.cm-ea.com" ;;
  staging) DOMAIN="staging-bms.cm-ea.com" ;;
  *) echo "Refused: environment must be dev or staging (got '$ENV_NAME'). Production is never deployed by this script." >&2; exit 2 ;;
esac
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || { echo "Refused: '$SHA' is not a full git sha." >&2; exit 2; }
[ -d "$SRC/laravel-app" ] || { echo "Refused: '$SRC' has no laravel-app/." >&2; exit 2; }

BASE="/var/www/eams-$ENV_NAME"
DB_NAME="bms_$ENV_NAME"
DB_USER="bms_$ENV_NAME"
ADMIN_EMAIL="admin@cm-ea.com"            # Let's Encrypt notices, as production
LOGINS="/root/bms-$ENV_NAME-logins.txt"
VHOST="/etc/apache2/sites-available/$DOMAIN.conf"

say() { printf '\n==> %s\n' "$*"; }

# ---------------------------------------------------------------- guards
# One deploy per environment at a time.
exec 9>"/var/lock/bms-$ENV_NAME.lock"
flock -n 9 || { echo "Another $ENV_NAME deploy is running." >&2; exit 1; }

for c in php mysql apache2ctl a2ensite certbot curl openssl flock; do
  command -v "$c" >/dev/null || { echo "Missing on server: $c (not installed by this script on a shared server)." >&2; exit 1; }
done
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || { echo "PHP 8.2+ required." >&2; exit 1; }

# What production looks like before we start; compared again at the end.
prod_snapshot() {
  local f
  for f in /etc/apache2/sites-available/bms.cm-ea.com.conf \
           /etc/apache2/sites-available/bms.cm-ea.com-le-ssl.conf \
           /var/www/eams/laravel-app/.env; do
    if [ -e "$f" ]; then sha256sum "$f"; else echo "absent  $f"; fi
  done
  echo "root-crontab $(crontab -l 2>/dev/null | sha256sum | cut -d' ' -f1)"
  # Exact names only: dev-bms.cm-ea.com and staging-bms.cm-ea.com also
  # contain "bms.cm-ea.com".
  echo "enabled $(ls -l /etc/apache2/sites-enabled/ | awk '$9 ~ /^bms\.cm-ea\.com(-le-ssl)?\.conf$/ {print $9, $11}' | tr '\n' ' ')"
}
PROD_BEFORE="$(prod_snapshot)"

check_prod_unchanged() {
  local after; after="$(prod_snapshot)"
  if [ "$after" != "$PROD_BEFORE" ]; then
    echo "PRODUCTION CHANGED during this $ENV_NAME deploy:" >&2
    diff <(echo "$PROD_BEFORE") <(echo "$after") >&2 || true
    return 1
  fi
  echo "Production unchanged: vhost, .env and root crontab are as before."
}
# Even when a step fails, say whether production was touched.
trap 'echo; echo "A step failed. Checking production anyway:"; check_prod_unchanged || true' ERR

# ---------------------------------------------------------------- layout
say "Release for $ENV_NAME at ${SHA:0:12}"
mkdir -p "$BASE/releases" "$BASE/shared"
for d in app/public framework/cache/data framework/sessions framework/views framework/testing logs; do
  mkdir -p "$BASE/shared/storage/$d"
done

REL="$BASE/releases/$(date -u +%Y%m%d%H%M%S)-${SHA:0:12}"
mv "$SRC" "$REL"
echo "$SHA" > "$REL/REVISION"
APP="$REL/laravel-app"
rm -rf "$APP/storage"
ln -s "$BASE/shared/storage" "$APP/storage"
ln -sfn "$BASE/shared/.env" "$APP/.env"

# ---------------------------------------------------------------- database
say "Database $DB_NAME"
ENV_FILE="$BASE/shared/.env"
old() { [ -f "$ENV_FILE" ] && grep -E "^$1=" "$ENV_FILE" | head -n1 | cut -d= -f2- || true; }
APP_KEY="$(old APP_KEY)"
DB_PASS="$(old DB_PASSWORD)"
[ -n "$DB_PASS" ] || DB_PASS="$(openssl rand -hex 24)"   # generated here, kept only in .env

mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -u root -e "ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -u root -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"

# ---------------------------------------------------------------- .env
# Rewritten on every deploy, so the neutral settings (mail to the log, no
# warm worker) can never drift. Only APP_KEY and the DB password carry over.
say ".env (mail to log, no background jobs)"
umask 027
cat > "$ENV_FILE" <<EOF
APP_NAME="BMS $ENV_NAME"
APP_ENV=$ENV_NAME
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=https://$DOMAIN
APP_TIMEZONE=UTC

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS

SESSION_DRIVER=database
SESSION_LIFETIME=120
CACHE_STORE=database
CACHE_PREFIX=bms_${ENV_NAME}_
QUEUE_CONNECTION=database

BCRYPT_ROUNDS=12
BI_AUTH_FALLBACK_ENABLED=false
BMS_WARM_ENABLED=false

MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-reply@cm-ea.com"
MAIL_FROM_NAME="BMS $ENV_NAME"
EOF
umask 022
chown root:www-data "$ENV_FILE"
chmod 640 "$ENV_FILE"

cd "$APP"
if [ -z "$APP_KEY" ]; then
  php artisan key:generate --force --no-interaction
fi

# ---------------------------------------------------------------- migrate + seed
say "Migrations and demo data"
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction      # idempotent: demo tenant + super admin

# The seeder's passwords ("changeme") are public in this repository. On a
# site anyone can reach, replace them once with random ones, kept in a
# root-only file on the server and never printed to the (public) CI log.
touch "$LOGINS"; chmod 600 "$LOGINS"
ROTATED="$(BMS_LOGINS_FILE="$LOGINS" php artisan tinker --execute='
$n = 0;
foreach (["super@local", "owner@demo.local"] as $e) {
    $u = App\Models\User::where("email", $e)->first();
    if ($u && Illuminate\Support\Facades\Hash::check("changeme", $u->password)) {
        $p = Illuminate\Support\Str::password(20, symbols: false);
        $u->password = $p;
        $u->save();
        file_put_contents(getenv("BMS_LOGINS_FILE"), gmdate("c")." ".$e." ".$p.PHP_EOL, FILE_APPEND);
        $n++;
    }
}
echo "rotated=".$n;
' 2>/dev/null | grep -oE 'rotated=[0-9]+' || echo 'rotated=?')"
echo "Demo logins: $ROTATED (new passwords, if any, are in $LOGINS on the server)"

# ---------------------------------------------------------------- caches + permissions
say "Caches and permissions"
php artisan storage:link --force --no-interaction >/dev/null 2>&1 || true
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
chown -R www-data:www-data "$REL" "$BASE/shared/storage"
chmod -R ug+rwX "$BASE/shared/storage" "$APP/bootstrap/cache"
chown root:www-data "$ENV_FILE"

# ---------------------------------------------------------------- switch
say "Switch $BASE/current"
ln -sfn "$REL" "$BASE/current.new"
mv -T "$BASE/current.new" "$BASE/current"

# ---------------------------------------------------------------- Apache
if [ ! -f "$VHOST" ]; then
  say "Apache site $DOMAIN"
  cat > "$VHOST" <<EOF
# BMS $ENV_NAME. Written by deploy/server/bms-env.sh; do not edit by hand.
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $BASE/current/laravel-app/public

    <Directory $BASE>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  \${APACHE_LOG_DIR}/${DOMAIN}_error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN}_access.log combined
</VirtualHost>
EOF
  a2ensite "$DOMAIN.conf" >/dev/null
  if ! apache2ctl configtest; then
    echo "Apache config test failed; taking the new site back out." >&2
    a2dissite "$DOMAIN.conf" >/dev/null || true
    rm -f "$VHOST"
    exit 1
  fi
fi
# A graceful reload also drops PHP's cached path of the old release.
apache2ctl configtest
systemctl reload apache2

if [ ! -d "/etc/letsencrypt/live/$DOMAIN" ]; then
  say "Let's Encrypt certificate for $DOMAIN"
  certbot --apache --non-interactive --agree-tos --redirect \
    --email "$ADMIN_EMAIL" --cert-name "$DOMAIN" -d "$DOMAIN"
  apache2ctl configtest
  systemctl reload apache2
fi

# ---------------------------------------------------------------- checks
say "Deploy check"
sleep 2
[ "$(cat "$BASE/current/REVISION")" = "$SHA" ] || { echo "current/ is not on $SHA" >&2; exit 1; }
CODE="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 \
  --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/t/demo/login" || echo 000)"
echo "https://$DOMAIN/t/demo/login -> HTTP $CODE (from the server itself)"
[ "$CODE" = "200" ] || { echo "Login page did not answer 200." >&2; tail -n 30 "$BASE/shared/storage/logs/laravel.log" 2>/dev/null | cut -c1-300 >&2 || true; exit 1; }

say "Production unchanged?"
check_prod_unchanged

# Keep the three newest releases.
ls -1dt "$BASE"/releases/*/ | tail -n +4 | while read -r old_rel; do
  [ "$(readlink -f "$BASE/current")/" = "$old_rel" ] || rm -rf "$old_rel"
done

say "Done: https://$DOMAIN runs ${SHA:0:12}"
