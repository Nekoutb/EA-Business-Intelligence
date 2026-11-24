# Sourced by bms-env.sh and bms-rebuild-staging.sh (not run on its own).
#
# Records what production BMS looks like on server 6 before a dev/staging
# job starts, and proves afterwards that it is byte-for-byte the same:
# its Apache site files, its .env, which of its sites are enabled, and
# root's crontab (which holds production's scheduler, warm worker and
# 07:00 client report).

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

prod_guard_start() {
  PROD_BEFORE="$(prod_snapshot)"
  # Even when a step fails, say whether production was touched.
  trap 'echo; echo "A step failed. Checking production anyway:"; check_prod_unchanged || true' ERR
}

check_prod_unchanged() {
  local after; after="$(prod_snapshot)"
  if [ "$after" != "$PROD_BEFORE" ]; then
    echo "PRODUCTION CHANGED during this job:" >&2
    diff <(echo "$PROD_BEFORE") <(echo "$after") >&2 || true
    return 1
  fi
  echo "Production unchanged: vhost, .env and root crontab are as before."
}
