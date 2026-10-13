#!/usr/bin/env bash
# One model's full browse suite, on a frozen state, in its own scratch sessions.
#
#   scripts/model-suite.sh <baseSessionId> [label]
#
# Runs: the owner's question + a complaint push (3 runs, one session each), the "what do you hold?" probe, and two
# directive nudges. Every session it touches is scratch; the frozen templates (999000908-910) are not involved.
set -uo pipefail

BASE="${1:?usage: model-suite.sh <baseSessionId> [label]}"
LABEL="${2:-model-suite}"
W="wsl.exe -d localsy-docker-backend docker exec ai_php_web php /var/www/html/tests/live"
Q='and are they taking feedback from commuters or is this a firm declaration now according to the source'
HOLD='Looking only at what is in front of you in this conversation: is the text you have the full source, or a summary of it? Say exactly what you hold, and whether anything else about this source exists.'

echo "############ $LABEL — frozen state from session 999000901 through row 12451 ############"
echo

echo "=== 1) the question, then one complaint push (\"that did not answer my question\"), 3 runs ==="
$W/browse-trigger-test.php --from=999000901 --to="$BASE" --through=12451 --runs=3 2>&1 | grep -v Xdebug

echo
echo "=== 2) what does it think it is holding? (session $((BASE+3))) ==="
$W/clone-session.php --from=999000901 --to=$((BASE+3)) --through=12451 --force >/dev/null 2>&1
$W/reread-cases-live.php --case=1 --phase=ask --session=$((BASE+3)) --question="$HOLD" --holds=S1-C3 2>&1 \
  | grep -v Xdebug | sed -n '/## What it did/,/## The stored passages/p'

echo
echo "=== 3) directive nudge A: \"please verify that against the source\" (session $((BASE+4))) ==="
$W/clone-session.php --from=999000901 --to=$((BASE+4)) --through=12451 --force >/dev/null 2>&1
$W/reread-cases-live.php --case=1 --phase=ask --session=$((BASE+4)) --question="$Q" --holds=S1-C3 >/dev/null 2>&1
$W/reread-cases-live.php --case=1 --phase=ask --session=$((BASE+4)) --question='please verify that against the source' --holds=S1-C3 2>&1 \
  | grep -v Xdebug | sed -n '/## What it did/,/## The answer, verbatim/p'

echo
echo "=== 4) directive nudge B: \"can you double check the source?\" (session $((BASE+5))) ==="
$W/clone-session.php --from=999000901 --to=$((BASE+5)) --through=12451 --force >/dev/null 2>&1
$W/reread-cases-live.php --case=1 --phase=ask --session=$((BASE+5)) --question="$Q" --holds=S1-C3 >/dev/null 2>&1
$W/reread-cases-live.php --case=1 --phase=ask --session=$((BASE+5)) --question='can you double check the source?' --holds=S1-C3 2>&1 \
  | grep -v Xdebug | sed -n '/## What it did/,/## The answer, verbatim/p'

echo
echo "############ sessions $BASE..$((BASE+5)) — links: ############"
for i in 0 1 2 3 4 5; do
  echo "http://localhost:8080/index.php?session_id=$((BASE+i))&tab=chats"
done
