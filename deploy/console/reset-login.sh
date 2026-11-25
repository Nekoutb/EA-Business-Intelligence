#!/usr/bin/env bash
# Give one console login a new, easy-to-type password (ticket T9).
#
#   reset-login.sh <login>
#
# Run by .github/workflows/console-reset-login.yml over SSH, as root.
# The new password goes to /root/bms-console-logins.txt (root only) and is
# never printed. Restarting the console also lifts any sign-in lockout.
set -euo pipefail

LOGIN="${1:-}"
[[ "$LOGIN" =~ ^[a-z0-9._]{2,32}$ ]] || { echo "Refused: '$LOGIN' is not a login name." >&2; exit 2; }
LOGINS="/root/bms-console-logins.txt"
touch "$LOGINS"; chmod 600 "$LOGINS"

BMS_LOGIN="$LOGIN" BMS_LOGINS_FILE="$LOGINS" python3 - <<'PY'
import os, sys, time
sys.path.insert(0, "/opt/bms-console/app")
import bms_console as c
name = os.environ["BMS_LOGIN"]
users = c.load_users()
if name not in users:
    sys.exit(f"No console login called '{name}'. Existing: {', '.join(sorted(users))}")
pw = c.easy_password()
users[name]["hash"] = c.hash_password(pw)
c.save_users(users)
with open(os.environ["BMS_LOGINS_FILE"], "a") as f:
    f.write(f"\n{time.strftime('%Y-%m-%d %H:%M', time.gmtime())} UTC, new password (replaces any above) for {name}:\n{name} {pw}\n")
print(f"New password set for '{name}'; it is at the end of {os.environ['BMS_LOGINS_FILE']}.")
PY

systemctl restart bms-console
echo "Console restarted (sign-in lockouts cleared)."
