#!/usr/bin/env bash
# Both arms of the terminology experiment on ONE loaded model, so the comparison needs a single model visit.
#
#   scripts/terminology-arm.sh <baseSessionId> <label>
#
# Arm "old" = the wording before 2026-10-11 (block named evidence, previous tool descriptions, no marker on the
# model's own summary). Arm "new" = the shipped wording. Each arm: 3 runs of the question + one complaint push,
# then the "what do you hold?" probe, then the two directive nudges.
set -uo pipefail

BASE="${1:?usage: terminology-arm.sh <baseSessionId> <label>}"
LABEL="${2:-terminology-arm}"
W="wsl.exe -d localsy-docker-backend docker exec ai_php_web php /var/www/html/tests/live"
Q='and are they taking feedback from commuters or is this a firm declaration now according to the source'
PUSH='that did not answer my question'
HOLD='Looking only at what is in front of you in this conversation: is the text you have the full source, or a summary of it? Say exactly what you hold, and whether anything else about this source exists.'

run_arm () {
  local arm="$1" base="$2"
  echo "############ ARM $arm — $LABEL ############"
  echo
  echo "=== $arm: question + complaint push, 3 runs ==="
  $W/browse-trigger-test.php --from=999000901 --to="$base" --through=12451 --runs=3 --arm="$arm" 2>&1 | grep -v Xdebug
  echo
  echo "=== $arm: what does it think it holds? (session $((base+3))) ==="
  $W/clone-session.php --from=999000901 --to=$((base+3)) --through=12451 --force >/dev/null 2>&1
  $W/reread-cases-live.php --case=1 --phase=ask --session=$((base+3)) --arm="$arm" --question="$HOLD" --holds=S1-C3 2>&1 \
    | grep -v Xdebug | sed -n '/## What it did/,/## The stored passages/p'
  echo
  echo "=== $arm: directive nudge \"please verify that against the source\" (session $((base+4))) ==="
  $W/clone-session.php --from=999000901 --to=$((base+4)) --through=12451 --force >/dev/null 2>&1
  $W/reread-cases-live.php --case=1 --phase=ask --session=$((base+4)) --arm="$arm" --question="$Q" --holds=S1-C3 >/dev/null 2>&1
  $W/reread-cases-live.php --case=1 --phase=ask --session=$((base+4)) --arm="$arm" --question='please verify that against the source' --holds=S1-C3 2>&1 \
    | grep -v Xdebug | sed -n '/## What it did/,/## The answer, verbatim/p'
  echo
}

run_arm old "$BASE"
run_arm new "$((BASE+10))"

echo "############ sessions $BASE..$((BASE+4)) (old) and $((BASE+10))..$((BASE+14)) (new) ############"
for i in 0 1 2 3 4; do echo "old $((BASE+i)): http://localhost:8080/index.php?session_id=$((BASE+i))&tab=chats"; done
for i in 0 1 2 3 4; do echo "new $((BASE+10+i)): http://localhost:8080/index.php?session_id=$((BASE+10+i))&tab=chats"; done
