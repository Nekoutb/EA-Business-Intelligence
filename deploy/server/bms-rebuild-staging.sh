#!/usr/bin/env bash
# Rebuild staging from production on server 6: replace staging's database
# with a copy of production's as it is now, then neutralise the copy.
#
#   bms-rebuild-staging.sh
#
# Run by .github/workflows/rebuild-staging.yml over SSH, as root.
#
# Data only moves production -> staging. Production's database is only
# READ (mysqldump --single-transaction: no locks, no writes). Anything
# entered in staging since the last rebuild is lost.
#
# Neutralised, then checked:
#   - every client and global setting whose name holds KEY, SECRET,
#     PASSWORD or TOKEN is blanked (Odoo and AI keys), and so is any value
#     stored encrypted (it was encrypted with production's APP_KEY);
#   - every client is pointed back at the shared copy (data_location
#     'shared', db_config cleared), so staging can never reach a client's
#     own database;
#   - sessions, cache, queued and failed jobs, and password-reset tokens
#     are emptied;
#   - staging's .env already sends mail to the log and staging has no
#     cron (bms-env.sh); both are checked again here.
# Users and their passwords are kept, so people log in as in production;
# the two seeded demo accounts lose the public "changeme" password.
set -euo pipefail

BASE="/var/www/eams-staging"
APP="$BASE/current/laravel-app"
ENV_FILE="$BASE/shared/.env"
PROD_ENV="/var/www/eams/laravel-app/.env"
DOMAIN="staging-bms.cm-ea.com"
LOGINS="/root/bms-staging-logins.txt"

say() { printf '\n==> %s\n' "$*"; }
val() { grep -E "^$1=" "$2" | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

# ---------------------------------------------------------------- guards
# Shares the staging deploy's lock: never a deploy and a rebuild at once.
exec 9>"/var/lock/bms-staging.lock"
flock -n 9 || { echo "A staging deploy or rebuild is running." >&2; exit 1; }

for c in php mysql mysqldump curl flock nice ionice; do
  command -v "$c" >/dev/null || { echo "Missing on server: $c" >&2; exit 1; }
done
[ -d "$APP" ] && [ -f "$ENV_FILE" ] || { echo "Staging is not deployed yet (push to the staging branch first)." >&2; exit 1; }

SRC_DB="$(val DB_DATABASE "$PROD_ENV")"
DST_DB="$(val DB_DATABASE "$ENV_FILE")"
DST_USER="$(val DB_USERNAME "$ENV_FILE")"
[ -n "$SRC_DB" ] || { echo "Cannot read production's database name." >&2; exit 1; }
[ "$DST_DB" = "bms_staging" ] || { echo "Refused: staging's database is '$DST_DB', expected bms_staging." >&2; exit 1; }
[ "$SRC_DB" != "$DST_DB" ] || { echo "Refused: source and target are the same database." >&2; exit 1; }

. "$(dirname "$0")/prod-guard.sh"
prod_guard_start

# ---------------------------------------------------------------- copy
say "Copy $SRC_DB (production, read only) -> $DST_DB"
SIZE_MB="$(mysql -u root -N -e "SELECT ROUND(SUM(data_length+index_length)/1048576) FROM information_schema.tables WHERE table_schema='$SRC_DB';")"
echo "Production database: about ${SIZE_MB} MB. Copying at low priority."
START=$(date +%s)

mysql -u root -e "DROP DATABASE IF EXISTS \`$DST_DB\`; CREATE DATABASE \`$DST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "GRANT ALL PRIVILEGES ON \`$DST_DB\`.* TO '$DST_USER'@'localhost'; FLUSH PRIVILEGES;"

nice -n 19 ionice -c3 mysqldump -u root --single-transaction --quick \
    --routines --triggers --no-tablespaces --set-gtid-purged=OFF "$SRC_DB" \
  | nice -n 19 ionice -c3 mysql -u root "$DST_DB"

echo "Copied in $(( $(date +%s) - START )) s."

# ---------------------------------------------------------------- neutralise
say "Neutralise the copy"
SECRET_RE='KEY|SECRET|PASSWORD|TOKEN'
mysql -u root "$DST_DB" <<SQL
UPDATE tenant_settings SET value = NULL, is_encrypted = 0
 WHERE UPPER(\`key\`) REGEXP '$SECRET_RE' OR is_encrypted = 1;
UPDATE global_settings SET value = NULL, is_encrypted = 0
 WHERE UPPER(\`key\`) REGEXP '$SECRET_RE' OR is_encrypted = 1;
UPDATE tenants SET data_location = 'shared', db_config = NULL
 WHERE data_location <> 'shared' OR db_config IS NOT NULL;
TRUNCATE TABLE sessions;
TRUNCATE TABLE cache;
TRUNCATE TABLE cache_locks;
TRUNCATE TABLE jobs;
TRUNCATE TABLE job_batches;
TRUNCATE TABLE failed_jobs;
TRUNCATE TABLE password_reset_tokens;
SQL
echo "Keys blanked, clients pointed at the shared copy, sessions/cache/jobs emptied."

# ---------------------------------------------------------------- staging's code
say "Bring the copy up to staging's code"
cd "$APP"
php artisan migrate --force --no-interaction

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
echo "Seeded demo accounts still on 'changeme' in the copy: $ROTATED (replaced; see $LOGINS)"
if [ "$ROTATED" != "rotated=0" ] && [ "$ROTATED" != "rotated=?" ]; then
  echo "NOTE: production itself still uses the public 'changeme' password for those accounts."
fi

php artisan cache:clear --no-interaction >/dev/null 2>&1 || true
chown -R www-data:www-data "$BASE/shared/storage"

# ---------------------------------------------------------------- checks
say "Neutralisation checks"
q() { mysql -u root -N "$DST_DB" -e "$1"; }
FAIL=0
check() {  # check <label> <actual> <expected>
  if [ "$2" = "$3" ]; then echo "  ok    $1"; else echo "  FAIL  $1 (got '$2', expected '$3')"; FAIL=1; fi
}
check "client secrets blank"      "$(q "SELECT COUNT(*) FROM tenant_settings WHERE (UPPER(\`key\`) REGEXP '$SECRET_RE' OR is_encrypted = 1) AND COALESCE(value,'') <> ''")" 0
check "global secrets blank"      "$(q "SELECT COUNT(*) FROM global_settings WHERE (UPPER(\`key\`) REGEXP '$SECRET_RE' OR is_encrypted = 1) AND COALESCE(value,'') <> ''")" 0
check "no client on its own DB"   "$(q "SELECT COUNT(*) FROM tenants WHERE data_location <> 'shared' OR db_config IS NOT NULL")" 0
# Staging stays reachable during the rebuild, so visitors may open new
# sessions; only sessions older than this rebuild would be copied ones.
check "no copied sessions"        "$(q "SELECT COUNT(*) FROM sessions WHERE last_activity < $START")" 0
check "no queued jobs"            "$(q "SELECT COUNT(*) FROM jobs")" 0
check "mail goes to the log"      "$(val MAIL_MAILER "$ENV_FILE")" log
check "no staging cron (root)"    "$(crontab -l 2>/dev/null | grep -c 'eams-staging' || true)" 0
check "no staging cron (cron.d)"  "$(grep -rl 'eams-staging' /etc/cron.d 2>/dev/null | wc -l)" 0
[ "$FAIL" = 0 ] || { echo "Neutralisation incomplete: see FAIL above." >&2; exit 1; }

echo
echo "Copied: $(q "SELECT COUNT(*) FROM tenants") clients, $(q "SELECT COUNT(*) FROM users") users."

CODE="$(curl -sk -L -o /dev/null -w '%{http_code}' --max-time 30 \
  --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/" || echo 000)"
echo "https://$DOMAIN/ (following redirects) -> HTTP $CODE"
[ "$CODE" = "200" ] || { echo "Staging does not answer 200 after the rebuild." >&2; exit 1; }

say "Production unchanged?"
check_prod_unchanged

say "Done: staging is a neutralised copy of production as of $(date -u '+%Y-%m-%d %H:%M UTC')"
