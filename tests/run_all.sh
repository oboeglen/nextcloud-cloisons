#!/bin/bash
# Campagne complète : remise à zéro, découverte, actions (2 séries), synthèse.
set -e
cd "$(dirname "$0")"
TAG=${1:-$(date +%Y%m%d-%H%M)}
mkdir -p out/$TAG
docker exec -u www-data cloisons-banc-app-1 php occ app:enable cloisons >/dev/null
docker exec -u www-data cloisons-banc-app-1 php occ upgrade >/dev/null 2>&1 || true
docker exec -u www-data cloisons-banc-app-1 php occ cloisons:mode enforce >/dev/null
./reset_bench.sh >/dev/null 2>&1
docker exec cloisons-banc-app-1 apachectl -k graceful 2>/dev/null; sleep 2
docker cp check_rules.php cloisons-banc-app-1:/tmp/check_rules.php
docker exec -u www-data cloisons-banc-app-1 php /tmp/check_rules.php > out/$TAG/rules.txt || { cat out/$TAG/rules.txt; exit 1; }
python3 audit_discovery.py out/$TAG/discovery.json > out/$TAG/discovery.log 2>&1
python3 audit_actions.py out/$TAG/actions.json > out/$TAG/actions.log 2>&1
python3 audit_actions2.py out/$TAG/actions2.json > out/$TAG/actions2.log 2>&1
./reset_bench.sh >/dev/null 2>&1
python3 audit_functions.py out/$TAG/functions.json > out/$TAG/functions.log 2>&1
python3 summarize.py out/$TAG > out/$TAG/summary.txt
cat out/$TAG/summary.txt
