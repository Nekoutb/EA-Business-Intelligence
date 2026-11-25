#!/usr/bin/env bash
# Install or update the BMS console on server 6 (ticket T9).
#
#   install.sh <unpacked deploy/ dir>
#
# Run by .github/workflows/console-deploy.yml over SSH, as root. Writes only:
#   /opt/bms-console/            app, the scripts its buttons run, a read-only
#                                clone of the (public) repo for its checks
#   /var/lib/bms-console/        logins (PBKDF2 hashes), job output, action log
#   /etc/systemd/system/bms-console.service   (127.0.0.1:8077 only)
#   /etc/apache2/sites-available/status-bms.cm-ea.com*.conf + its certificate
#   /root/bms-console-logins.txt first passwords, root only, never printed
# and ends by proving production is unchanged.
set -euo pipefail

SRC="${1:?usage: install.sh <deploy dir>}"
HOME_DIR="/opt/bms-console"
STATE="/var/lib/bms-console"
DOMAIN="status-bms.cm-ea.com"
VHOST="/etc/apache2/sites-available/$DOMAIN.conf"
PORT=8077
REPO_URL="https://github.com/Nekoutb/EA-Business-Intelligence.git"
LOGINS="/root/bms-console-logins.txt"

say() { printf '\n==> %s\n' "$*"; }
. "$SRC/server/prod-guard.sh"
prod_guard_start

say "Files"
mkdir -p "$HOME_DIR/app" "$HOME_DIR/bin" "$STATE/jobs"
chmod 700 "$STATE"
install -m 0644 "$SRC/console/bms_console.py" "$HOME_DIR/app/bms_console.py"
for f in bms-rebuild-staging.sh bms-promote.sh prod-guard.sh; do
  install -m 0755 "$SRC/server/$f" "$HOME_DIR/bin/$f"
done
if [ -d "$HOME_DIR/repo/.git" ]; then
  git -C "$HOME_DIR/repo" fetch --quiet --prune origin
else
  git clone --quiet "$REPO_URL" "$HOME_DIR/repo"
fi
echo "Installed in $HOME_DIR"

say "Logins"
if [ ! -f "$STATE/users.json" ]; then
  touch "$LOGINS"; chmod 600 "$LOGINS"
  BMS_LOGINS_FILE="$LOGINS" BMS_CONSOLE_STATE="$STATE" python3 - <<'PY'
import json, os, secrets, sys
sys.path.insert(0, "/opt/bms-console/app")
import bms_console as c
users, lines = {}, []
for name in ("owner", "praise"):
    pw = secrets.token_urlsafe(15)
    users[name] = {"hash": c.hash_password(pw), "admin": True}
    lines.append(f"{name} {pw}")
c.save_users(users)
with open(os.environ["BMS_LOGINS_FILE"], "a") as f:
    f.write("BMS console https://status-bms.cm-ea.com (change these after first sign-in, under People)\n")
    f.write("\n".join(lines) + "\n")
print("Created logins 'owner' and 'praise' (administrators); passwords in", os.environ["BMS_LOGINS_FILE"])
PY
else
  echo "Logins already exist; unchanged."
fi

say "Service"
cat > /etc/systemd/system/bms-console.service <<EOF
[Unit]
Description=BMS console (https://$DOMAIN), ticket T9
After=network-online.target

[Service]
ExecStart=/usr/bin/python3 $HOME_DIR/app/bms_console.py
Environment=BMS_CONSOLE_PORT=$PORT
Restart=on-failure
RestartSec=3
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --quiet bms-console
systemctl restart bms-console
for i in $(seq 1 20); do
  curl -fs -o /dev/null "http://127.0.0.1:$PORT/login" && break
  sleep 1
done
curl -fs -o /dev/null "http://127.0.0.1:$PORT/login" || { journalctl -u bms-console -n 30 --no-pager; echo "Console did not start." >&2; exit 1; }
echo "bms-console is running on 127.0.0.1:$PORT"

say "Apache site $DOMAIN"
if [ ! -f "$VHOST" ]; then
  cat > "$VHOST" <<EOF
# BMS console. Written by deploy/console/install.sh; do not edit by hand.
<VirtualHost *:80>
    ServerName $DOMAIN
    ProxyPreserveHost On
    ProxyPass /.well-known/acme-challenge/ !
    ProxyPass / http://127.0.0.1:$PORT/
    ProxyPassReverse / http://127.0.0.1:$PORT/
    ErrorLog  \${APACHE_LOG_DIR}/${DOMAIN}_error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN}_access.log combined
</VirtualHost>
EOF
  a2ensite "$DOMAIN.conf" >/dev/null
  if ! apache2ctl configtest; then
    a2dissite "$DOMAIN.conf" >/dev/null || true; rm -f "$VHOST"
    echo "Apache config test failed; site taken back out." >&2; exit 1
  fi
  systemctl reload apache2
fi
if [ ! -d "/etc/letsencrypt/live/$DOMAIN" ]; then
  certbot --apache --non-interactive --agree-tos --redirect \
    --email admin@cm-ea.com --cert-name "$DOMAIN" -d "$DOMAIN"
  apache2ctl configtest
  systemctl reload apache2
fi

say "Checks"
sleep 2
CODE="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/login" || echo 000)"
echo "https://$DOMAIN/login -> HTTP $CODE (from the server itself)"
[ "$CODE" = "200" ] || { echo "Console page did not answer 200." >&2; exit 1; }
[ -f /root/.ssh/bms_console_promote ] && echo "Promote key present on the server." || echo "NOTE: no Promote key yet (run the console-key workflow)."
check_prod_unchanged
say "Done: https://$DOMAIN"
