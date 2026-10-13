#!/usr/bin/env bash
# One model's turn on the nudge-ladder benchmark. Load the model in the app first — the row records whatever
# model the app reports, so a run cannot be mislabelled.
#
#   scripts/model-bench.sh <baseSessionId> "<label>" [runs]
#
# Frozen state: session 999000901 through row 12451 (one fetched article, its notes, two model-written
# summaries). The question, the ladder and the pass/fail definitions live in bench-ladder.php.
set -uo pipefail

BASE="${1:?usage: model-bench.sh <baseSessionId> \"<label>\" [runs]}"
LABEL="${2:-unnamed model}"
RUNS="${3:-3}"
W="wsl.exe -d localsy-docker-backend docker exec ai_php_web php /var/www/html/tests/live"
Q='and was anything said about listening to commuters feedback seriously?'

echo "############ nudge ladder — $LABEL — $RUNS run(s) ############"
echo "frozen state: session 999000901 through row 12451 · question in the owner's words · ladder: 1 complaint, 2 direct question, 3 imperative"
echo

for i in $(seq 1 "$RUNS"); do
  s=$((BASE + i))
  $W/clone-session.php --from=999000901 --to="$s" --through=12451 --force >/dev/null 2>&1
  $W/bench-ladder.php --session="$s" --question="$Q" --label="$LABEL" --holds=S1-C3 2>&1 | grep -v Xdebug
  echo
done

echo "############ summary from the recorded rows ############"
$W/report-bench-rows.php --label="$LABEL" 2>&1 | grep -v Xdebug
