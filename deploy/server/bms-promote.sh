#!/usr/bin/env bash
# Promote staging -> prod from server 6 (the console's button, ticket T9).
#
#   bms-promote.sh [--dry-run]
#
# The same three checks as .github/workflows/promote.yml; refused with
# nothing changed unless all hold:
#   1. staging runs the head of the staging branch (read from the server
#      itself) and answers;
#   2. the Tests workflow passed on that exact revision;
#   3. prod can be fast-forwarded to it.
# Then it pushes that revision to prod (and master, kept in step) with the
# console's deploy key, /root/.ssh/bms_console_promote, whose public half
# the repo owner added as a deploy key with write access. That push starts
# "Deploy to Production" on GitHub; this script waits for it and reports.
set -euo pipefail

DRY=0; [ "${1:-}" = "--dry-run" ] && DRY=1
REPO="Nekoutb/EA-Business-Intelligence"
CLONE="/opt/bms-console/repo"
KEY="/root/.ssh/bms_console_promote"
STAGING_REV_FILE="/var/www/eams-staging/current/REVISION"

refuse() { echo; echo "REFUSED: $*"; echo "Nothing was changed."; exit 1; }
api() { curl -fsS --max-time 20 -H "Accept: application/vnd.github+json" -H "User-Agent: bms-console" "https://api.github.com/repos/$REPO/$1"; }
json() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1], {'d': d}))" "$1"; }

[ $DRY = 1 ] && echo "Dry run: checks only, nothing will be changed." && echo

git -C "$CLONE" fetch --quiet --prune origin
SHA="$(git -C "$CLONE" rev-parse origin/staging)"
echo "Staging branch head: ${SHA:0:12}  $(git -C "$CLONE" log -1 --format=%s "$SHA")"

# 1. staging runs it, and answers
RUNNING="$(cat "$STAGING_REV_FILE" 2>/dev/null || true)"
[ "$RUNNING" = "$SHA" ] || refuse "staging runs ${RUNNING:0:12}, but the staging branch is at ${SHA:0:12}. Wait for its deploy to finish."
CODE="$(curl -s -L -o /dev/null -w '%{http_code}' --max-time 30 https://staging-bms.cm-ea.com/ || echo 000)"
[ "$CODE" = "200" ] || refuse "staging-bms.cm-ea.com does not answer (HTTP $CODE)."
echo "ok  staging runs ${SHA:0:12} and answers"

# 2. tests green on that revision
GREEN="$(api "actions/workflows/tests.yml/runs?head_sha=$SHA&status=success&per_page=1" | json "d['total_count']" || echo 0)"
[ "${GREEN:-0}" -gt 0 ] || refuse "the Tests workflow has not passed on ${SHA:0:12}."
echo "ok  tests passed on ${SHA:0:12}"

# 3. fast-forward only
CURRENT="$(git -C "$CLONE" rev-parse --verify --quiet origin/prod || true)"
[ -n "$CURRENT" ] || refuse "there is no prod branch."
[ "$CURRENT" != "$SHA" ] || refuse "prod is already at ${SHA:0:12}; nothing to promote."
git -C "$CLONE" merge-base --is-ancestor "$CURRENT" "$SHA" \
  || refuse "prod has commits that staging does not (not a fast-forward). Bring them into staging first."
echo "ok  prod ${CURRENT:0:12} -> ${SHA:0:12} is a fast-forward. What goes live:"
git -C "$CLONE" log --format='      %h %s' "$CURRENT..$SHA" | head -30

if [ $DRY = 1 ]; then echo; echo "Dry run: all checks pass. Nothing was changed."; exit 0; fi

# ---- promote
echo
echo "Pushing ${SHA:0:12} to prod (pressed by ${BMS_ACTOR:-unknown})..."
export GIT_SSH_COMMAND="ssh -i $KEY -o IdentitiesOnly=yes -o BatchMode=yes"
if ! OUT="$(git -C "$CLONE" push "git@github.com:$REPO.git" "$SHA:refs/heads/prod" 2>&1)"; then
  echo "$OUT"
  if echo "$OUT" | grep -qiE 'denied|publickey|not allowed|write access'; then
    refuse "GitHub did not accept the console's key. The repo owner adds it once: Settings -> Deploy keys, with write access."
  fi
  refuse "the push to prod failed (see above)."
fi
echo "prod is now at ${SHA:0:12}."
# master is the default branch; keep it showing what is live (best effort).
git -C "$CLONE" push "git@github.com:$REPO.git" "$SHA:refs/heads/master" >/dev/null 2>&1 \
  && echo "master moved along too." || echo "(master not moved; not needed for the live site)"

# ---- follow GitHub's production deploy
echo
echo "GitHub is deploying the live site: https://github.com/$REPO/actions/workflows/deploy.yml"
for i in $(seq 1 120); do          # up to 30 minutes
  sleep 15
  STATUS="$(api "actions/workflows/deploy.yml/runs?head_sha=$SHA&per_page=1" \
    | json "' '.join(str(x) for x in (lambda r: (r.get('status', 'waiting'), r.get('conclusion')))((d['workflow_runs'] or [{}])[0]))" \
    2>/dev/null || echo 'waiting None')"
  case "$STATUS" in
    "completed success") echo "Live site deployed: ${SHA:0:12}. https://bms.cm-ea.com"; exit 0 ;;
    completed*) echo "The production deploy did NOT succeed ($STATUS). Check it on GitHub; the previous version keeps running until a deploy succeeds."; exit 1 ;;
    *) [ $((i % 4)) = 0 ] && echo "  ...still deploying ($STATUS)" ;;
  esac
done
echo "Still not finished after 30 minutes; follow it on GitHub."
exit 1
