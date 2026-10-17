#!/bin/bash
# Remet le banc dans un état propre : comptes fictifs recréés, jetons régénérés,
# cloison « famille » reconstituée, puis instantané SQL « clean ».
set -e
E="docker exec -u www-data cloisons-banc-app-1 php occ"
CONF=${CLOISONS_CONF:-"$(cd "$(dirname "$0")/../bench" && pwd)/conf"}
for u in fam-anne fam-paul client-aaaaaaaa client-bbbbbbbb client-cccccccc client-dddddddd; do
  $E user:delete "$u" >/dev/null 2>&1 || true
done
$E circles:maintenance >/dev/null 2>&1 || true
"$(dirname "$0")/seed_users.sh"
umask 077
echo "{" > $CONF/apppw.json; first=1
for u in fam-anne fam-paul client-aaaaaaaa client-bbbbbbbb client-cccccccc client-dddddddd; do
  t=$(docker exec -u www-data -e NC_PASS="$(cat $CONF/testpw)" cloisons-banc-app-1 php occ user:auth-tokens:add --password-from-env $u 2>&1 | grep -oE '[A-Za-z0-9]{72}' | head -1)
  [ $first = 1 ] || echo "," >> $CONF/apppw.json; first=0
  echo "\"$u\":\"$t\"" >> $CONF/apppw.json
done
echo "}" >> $CONF/apppw.json
sed -n '/agenda et carnet/,$p' "$(dirname "$0")/seed_users.sh" | bash
$E cloisons:zone create famille Famille >/dev/null 2>&1 || true
$E cloisons:zone add famille fam-anne >/dev/null 2>&1 || true
$E cloisons:zone add famille fam-paul >/dev/null 2>&1 || true
echo RESET_OK
# purge des restes de tests (banc uniquement) : salons, formulaires, équipes non personnelles, partages
. "${CLOISONS_BENCH:-$(cd "$(dirname "$0")/../bench" && pwd)}/.env"
docker exec -e MYSQL_PWD="$DBROOT" cloisons-banc-db-1 mariadb -uroot nextcloud -e "
DELETE FROM oc_talk_attendees; DELETE FROM oc_talk_sessions; DELETE FROM oc_talk_rooms; DELETE FROM oc_comments WHERE object_type='chat';
DELETE FROM oc_forms_v2_shares; DELETE FROM oc_forms_v2_options; DELETE FROM oc_forms_v2_questions; DELETE FROM oc_forms_v2_answers; DELETE FROM oc_forms_v2_submissions; DELETE FROM oc_forms_v2_forms;
DELETE FROM oc_share WHERE share_type<>3; DELETE FROM oc_dav_shares; DELETE FROM oc_notifications; DELETE FROM oc_activity; DELETE FROM oc_activity_mq; DELETE FROM oc_bookmarks_shares; DELETE FROM oc_bookmarks_shared_folders; DELETE FROM oc_bookmarks_shared_to_shares;
DELETE m FROM oc_circles_member m JOIN oc_circles_circle c ON c.unique_id=m.circle_id WHERE (c.config & 1)=0 AND (c.config & 2)=0 AND c.source<>1;
DELETE FROM oc_circles_circle WHERE (config & 1)=0 AND (config & 2)=0 AND source<>1;"
docker exec -u www-data cloisons-banc-app-1 php occ circles:sync >/dev/null 2>&1 || true
echo PURGE_OK
