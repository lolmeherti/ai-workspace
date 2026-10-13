#!/usr/bin/env bash
# Arm C: the absence rule, tested on the exact question where the failure was observed.
#
#   scripts/absence-arm.sh <baseSessionId> <label>
#
# The case is the owner's own question from his session — "and was anything said about listening to commuters
# feedback seriously?" — where Bonsai reasoned "the source notes do not explicitly mention…" and then answered that
# the source does not state it, while the stored passage contains the operator saying they take passenger feedback
# very seriously. Three runs per arm, same frozen state, one build.
#
# Reported per turn: whether it retrieved, and whether the answer carries the statement (checked by string against
# the passage's own words: "seriously", "Rückmeldungen", "take the feedback").
set -uo pipefail

BASE="${1:?usage: absence-arm.sh <baseSessionId> <label>}"
LABEL="${2:-absence-arm}"
W="wsl.exe -d localsy-docker-backend docker exec ai_php_web php /var/www/html/tests/live"
Q='and was anything said about listening to commuters feedback seriously?'

for arm in new rule; do
  echo "############ ARM $arm — $LABEL ############"
  off=0
  [ "$arm" = "rule" ] && off=10
  retrieved=0
  wentWeb=0
  carried=0
  for i in 1 2 3; do
    s=$((BASE + off + i))
    $W/clone-session.php --from=999000901 --to="$s" --through=12451 --force >/dev/null 2>&1
    out=$($W/reread-cases-live.php --case=1 --phase=ask --session="$s" --arm="$arm" --question="$Q" --holds=S1-C3 2>&1 | grep -v Xdebug)
    tools=$(echo "$out" | sed -n '/Tool calls, in order:/,/Context Data rows/p' | grep -E '  - ' | grep -v 'none')
    stored=$(echo "$tools" | grep -c 'search_session_evidence')
    web=$(echo "$tools" | grep -c 'search_web')
    hit=$(echo "$out" | sed -n '/## The answer, verbatim/,/## The stored passages/p' | grep -ciE 'seriously|Rückmeldungen|take the feedback')
    [ "$stored" -gt 0 ] && retrieved=$((retrieved+1))
    [ "$web" -gt 0 ] && wentWeb=$((wentWeb+1))
    [ "$hit" -gt 0 ] && carried=$((carried+1))
    printf 'run %d (session %d): stored-source reads %d, web searches %d\n' "$i" "$s" "$stored" "$web"
    printf '   answer carries the statement: %s\n' "$([ "$hit" -gt 0 ] && echo YES || echo no)"
  done
  printf '=> %s: stored-source read %d/3 · web search %d/3 · statement in the answer %d/3\n\n' "$arm" "$retrieved" "$wentWeb" "$carried"
done

echo "sessions $BASE..$((BASE+3)) (new) · $((BASE+10))..$((BASE+13)) (rule)"
