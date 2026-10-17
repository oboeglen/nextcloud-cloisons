#!/bin/bash
# Comptes fictifs du banc (aucune donnée réelle)
set -e
CONF=${CLOISONS_CONF:-"$(cd "$(dirname "$0")/../bench" && pwd)/conf"}
BASE=${CLOISONS_BASE:-http://127.0.0.1:18980}
E="docker exec -u www-data cloisons-banc-app-1 php occ"
for g in "Assistant IA" E2EE Developpement Domotique IA Office Photos Medias "Intranet" Signatures Talk Coffre; do $E group:add "$g" >/dev/null 2>&1 || true; done
mk(){ uid=$1; dn=$2; mail=$3; shift 3
  docker exec -u www-data -e OC_PASS="$PW" cloisons-banc-app-1 php occ user:add --password-from-env --display-name="$dn" "$uid" >/dev/null
  $E user:setting -- "$uid" settings email "$mail" >/dev/null
  for g in "$@"; do $E group:adduser "$g" "$uid" >/dev/null; done; }
PW=$(cat "$CONF/testpw")
mk fam-anne "Famille Anne" fam-anne@example.test admin "Assistant IA" E2EE Developpement Domotique IA Office Photos Medias "Intranet" Signatures Talk Coffre
mk fam-paul "Famille Paul" fam-paul@example.test Domotique IA Office Photos Medias "Intranet" Talk
mk client-aaaaaaaa "Alice Client" alice@example.test Office Photos Talk Signatures Coffre IA
mk client-bbbbbbbb "Bruno Client" bruno@example.test Office Photos Talk Signatures Coffre IA
mk client-cccccccc "Chloé Client" chloe@example.test Office
mk client-dddddddd "David Client" david@example.test
# agenda et carnet par défaut (créés normalement à la première connexion web)
for u in fam-anne fam-paul client-aaaaaaaa client-bbbbbbbb client-cccccccc client-dddddddd; do
  P=$(python3 -c "import json;print(json.load(open('$CONF/apppw.json'))['$u'])" 2>/dev/null) || continue
  curl -s -o /dev/null -u "$u:$P" -X MKCALENDAR "$BASE/remote.php/dav/calendars/$u/personal/" \
    -H 'Content-Type: application/xml' --data '<?xml version="1.0"?><c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:set><d:prop><d:displayname>Personnel</d:displayname></d:prop></d:set></c:mkcalendar>'
  curl -s -o /dev/null -u "$u:$P" -X MKCOL "$BASE/remote.php/dav/addressbooks/users/$u/contacts/" \
    -H 'Content-Type: application/xml' --data '<?xml version="1.0"?><d:mkcol xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:set><d:prop><d:resourcetype><d:collection/><card:addressbook/></d:resourcetype><d:displayname>Contacts</d:displayname></d:prop></d:set></d:mkcol>'
done
