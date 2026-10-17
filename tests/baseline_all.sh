#!/bin/bash
# Mesures de référence SANS l'application (banc remis à zéro avant chaque série).
cd "$(dirname "$0")"; mkdir -p out
E="docker exec -u www-data cloisons-banc-app-1 php occ"
run(){ $E app:enable cloisons >/dev/null; ./reset_bench.sh >/dev/null 2>&1; $E app:disable cloisons >/dev/null
  docker exec cloisons-banc-app-1 apachectl -k graceful 2>/dev/null; sleep 3
  python3 "$1" "out/baseline_$2.json" > "out/baseline_$2.log" 2>&1; echo "référence $2 : code $?"; }
run audit_discovery.py discovery
run audit_actions.py actions
run audit_actions2.py actions2
run audit_functions.py functions
$E app:enable cloisons >/dev/null
