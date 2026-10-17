#!/usr/bin/env python3
"""Audit de découverte : un compte peut-il apprendre l'existence ou l'identité
d'un compte hors de sa cloison ? Chaque vecteur est testé pour chaque paire
(acteur, cible) avec plusieurs termes de recherche.

Sorties :
  LEAK    identité d'un compte caché présente dans la réponse
  ORACLE  réponse différente entre un compte caché existant et un compte inexistant
  MISS    un compte de la même cloison n'est PAS trouvé (régression fonctionnelle)
"""
import json
import sys
import urllib.parse as up

from lib import (ACTORS, BASE, GHOST, HOST, USERS, ZONE, Client, find_leaks, hidden_for,
                 norm, visible_for)

OWN_FILE = {}  # fichier appartenant à l'acteur (pour les routes qui exigent un fichier réel)

RESULTS = []


def rec(kind, vector, actor, detail):
    from lib import ADMINS, ADMIN_VECTORS
    if actor in ADMINS and kind in ("LEAK", "ORACLE") and vector.startswith(ADMIN_VECTORS):
        return
    RESULTS.append({"kind": kind, "vector": vector, "actor": actor, "detail": detail})


def terms_for(target):
    dn, mail = USERS[target]
    t = {target, target[:8]}
    if dn and dn != target:
        t.add(dn)
        t.add(dn.split()[0][:4])
    if mail:
        t.add(mail)
        t.add(mail.split("@")[0])
    return sorted(t)


def found_any(text, target):
    from lib import markers
    return any(m in text or json.dumps(m)[1:-1] in text for m in markers(target))


# ---------------------------------------------------------------- recherches
def search_vectors(c, term):
    q = up.quote(term)
    yield "sharees", c.ocs("GET", f"/apps/files_sharing/api/v1/sharees?search={q}&itemType=file&perPage=50")
    yield "sharees_recommended", c.ocs("GET", "/apps/files_sharing/api/v1/sharees_recommended?itemType=file")
    for it in ("files", "call", "deck", "calendar"):
        yield f"autocomplete[{it}]", c.ocs("GET", f"/core/autocomplete/get?search={q}&itemType={it}&itemId=1&limit=50"
                                              "&shareTypes[]=0&shareTypes[]=1&shareTypes[]=4&shareTypes[]=7")
    yield "contactsmenu", c.req("POST", "/index.php/contactsmenu/contacts", csrf=True, data={"filter": term})
    yield "cloud_users", c.ocs("GET", f"/cloud/users?search={q}")
    yield "cloud_users_details", c.ocs("GET", f"/cloud/users/details?search={q}")
    yield "cloud_users_disabled", c.ocs("GET", f"/cloud/users/disabled?search={q}")
    yield "cloud_groups", c.ocs("GET", f"/cloud/groups?search={q}")
    yield "cloud_groups_details", c.ocs("GET", f"/cloud/groups/details?search={q}")
    yield "cal_attendee", c.req("POST", "/index.php/apps/calendar/v1/autocompletion/attendee", csrf=True, json={"search": term})
    yield "talk_listed", c.ocs("GET", f"/apps/spreed/api/v4/listed-room?searchTerm={q}")
    yield "circles_search", c.ocs("GET", f"/apps/circles/search?term={q}")
    yield "circles_listing", c.req("GET", f"/index.php/apps/circles/listing?term={q}", csrf=True)
    yield "text_users", c.req("POST", "/index.php/apps/text/api/v1/users", csrf=True, data={"filter": term, "limit": 50})
    yield "libresign_identify", c.ocs("GET", f"/apps/libresign/api/v1/identify-account/search?search={q}&method=account")
    yield "libresign_identify_email", c.ocs("GET", f"/apps/libresign/api/v1/identify-account/search?search={q}&method=email")
    if OWN_FILE.get(c.uid):
        yield "onlyoffice_users", c.req("GET", f"/index.php/apps/onlyoffice/ajax/users?fileId={OWN_FILE[c.uid]}&operationType=mention&search={q}", csrf=True)
    yield "by_phone", c.ocs("POST", "/cloud/users/search/by-phone", json={"location": "FR", "search": {"k": ["+33612345678"]}})
    yield "collab_collections", c.ocs("GET", f"/collaboration/resources/collections/search/{q}")
    # DAV : recherche de principaux
    body_pps = f"""<?xml version="1.0"?><d:principal-property-search xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns"
 xmlns:cal="urn:ietf:params:xml:ns:caldav" test="anyof"><d:property-search><d:prop><d:displayname/></d:prop>
 <d:match>{term}</d:match></d:property-search><d:property-search><d:prop><s:email-address/></d:prop><d:match>{term}</d:match>
 </d:property-search><d:prop><d:displayname/><s:email-address/><cal:calendar-user-address-set/></d:prop>
 <d:apply-to-principal-collection-set/></d:principal-property-search>"""
    yield "dav_principal_search", c.dav("REPORT", "/", body_pps, depth=0)
    body_cps = f"""<?xml version="1.0"?><x0:calendarserver-principal-search xmlns:x0="http://calendarserver.org/ns/"
 xmlns:d="DAV:" context="attendee"><x0:search-token>{term}</x0:search-token><x0:limit><x0:nresults>50</x0:nresults>
 </x0:limit><d:prop><d:displayname/></d:prop></x0:calendarserver-principal-search>"""


# ------------------------------------------------- listes sans terme de recherche
def list_vectors(c):
    yield "cloud_users_all", c.ocs("GET", "/cloud/users")
    yield "cloud_users_details_all", c.ocs("GET", "/cloud/users/details")
    yield "cloud_groups_all", c.ocs("GET", "/cloud/groups")
    for g in ("admin", "Office", "Talk", "Photos", "IA", "Signatures", "Coffre", "Domotique", "Medias"):
        gq = up.quote(g)
        yield f"group_users[{g}]", c.ocs("GET", f"/cloud/groups/{gq}/users")
        yield f"group_users_details[{g}]", c.ocs("GET", f"/cloud/groups/{gq}/users/details")
        yield f"group[{g}]", c.ocs("GET", f"/cloud/groups/{gq}")
        yield f"dav_group_principal[{g}]", c.dav("PROPFIND", f"/principals/groups/{gq}/",
            '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:group-member-set/><d:displayname/></d:prop></d:propfind>', depth=0)
    yield "self_groups_details", c.ocs("GET", f"/cloud/users/{c.uid}/groups/details")
    yield "user_status_all", c.ocs("GET", "/apps/user_status/api/v1/statuses")
    yield "dashboard_statuses", c.ocs("GET", "/apps/dashboard/api/v3/statuses")
    yield "contactsmenu_all", c.req("POST", "/index.php/contactsmenu/contacts", csrf=True, data={"filter": ""})
    yield "contactsmenu_teams", c.req("GET", "/index.php/contactsmenu/teams", csrf=True)
    yield "circles_all", c.ocs("GET", "/apps/circles/circles")
    yield "circles_probe", c.ocs("GET", "/apps/circles/probecircles")
    yield "teams_widget", c.ocs("GET", "/apps/circles/teams/dashboard/widget")
    yield "talk_listed_all", c.ocs("GET", "/apps/spreed/api/v4/listed-room?searchTerm=")
    yield "talk_rooms", c.ocs("GET", "/apps/spreed/api/v4/room")
    yield "onlyoffice_emails", c.req("GET", "/index.php/apps/onlyoffice/ajax/emails", csrf=True)
    yield "dav_principals_list", c.dav("PROPFIND", "/principals/users/",
        '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>', depth=1)
    yield "dav_principals_groups", c.dav("PROPFIND", "/principals/groups/",
        '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>', depth=1)
    yield "dav_system_ab", c.dav("PROPFIND", "/addressbooks/system/system/system/",
        '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:prop><card:address-data/></d:prop></d:propfind>', depth=1)
    yield "dav_self_ab_list", c.dav("PROPFIND", f"/addressbooks/users/{c.uid}/",
        '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>', depth=1)
    yield "dav_self_system_ab", c.dav("REPORT", f"/addressbooks/users/{c.uid}/z-server-generated--system/",
        '<?xml version="1.0"?><card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:prop><card:address-data/></d:prop></card:addressbook-query>', depth=1)
    r = c.ocs("GET", "/search/providers")
    yield "search_providers", r
    try:
        provs = [p["id"] for p in r.json()["ocs"]["data"]]
    except Exception:
        provs = []
    for p in provs:
        for term in ("Client", "Famille", "example", "client-"):
            yield f"unified_search[{p}:{term}]", c.ocs("GET", f"/search/providers/{p}/search?term={up.quote(term)}&limit=50")


# ------------------------------------------- vecteurs ciblés (uid) + oracles
def target_vectors(c, uid):
    q = up.quote(uid)
    yield "cloud_user", c.ocs("GET", f"/cloud/users/{q}")
    yield "cloud_user_groups", c.ocs("GET", f"/cloud/users/{q}/groups")
    yield "cloud_user_groups_details", c.ocs("GET", f"/cloud/users/{q}/groups/details")
    yield "cloud_user_subadmins_details", c.ocs("GET", f"/cloud/users/{q}/subadmins/details")
    yield "cloud_user_fields", c.ocs("GET", f"/cloud/user/fields/{q}")
    yield "profile_api", c.ocs("GET", f"/profile/{q}")
    yield "profile_page", c.req("GET", f"/index.php/u/{q}")
    yield "hovercard", c.ocs("GET", f"/hovercard/v1/{q}")
    yield "user_status", c.ocs("GET", f"/apps/user_status/api/v1/statuses/{q}")
    yield "avatar", c.req("GET", f"/index.php/avatar/{q}/64")
    yield "avatar_nofallback", c.req("GET", f"/index.php/avatar/{q}/64?guestFallback=false")
    yield "displaynames", c.req("POST", "/index.php/displaynames", csrf=True, json={"users": [uid]})
    yield "contactsmenu_findone", c.req("POST", "/index.php/contactsmenu/findOne", csrf=True, data={"shareType": 0, "shareWith": uid})
    yield "e2ee_pubkey", c.ocs("GET", f"/apps/end_to_end_encryption/api/v1/public-key?users={up.quote(json.dumps([uid]))}")
    yield "identityproof", c.ocs("GET", f"/identityproof/key/{q}@{HOST}")
    yield "ooo_now", c.ocs("GET", f"/apps/dav/api/v1/outOfOffice/{q}/now")
    yield "ooo", c.ocs("GET", f"/apps/dav/api/v1/outOfOffice/{q}")
    yield "appointments_page", c.req("GET", f"/index.php/apps/calendar/appointments/{q}")
    yield "onlyoffice_userinfo", c.req("GET", f"/index.php/apps/onlyoffice/ajax/userInfo?userIds={up.quote(json.dumps([uid]))}", csrf=True)
    yield "talk_proxy_avatar", c.ocs("GET", f"/apps/spreed/api/v1/proxy/new/user-avatar/64?cloudId={q}")
    yield "dav_principal", c.dav("PROPFIND", f"/principals/users/{q}/",
        '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns"><d:prop><d:displayname/><s:email-address/></d:prop></d:propfind>', depth=0)
    yield "dav_files_root", c.dav("PROPFIND", f"/files/{q}/", None, depth=0)
    yield "dav_calendars_home", c.dav("PROPFIND", f"/calendars/{q}/", None, depth=0)
    yield "dav_addressbooks_home", c.dav("PROPFIND", f"/addressbooks/users/{q}/", None, depth=0)
    yield "dav_uploads", c.dav("PROPFIND", f"/uploads/{q}/", None, depth=0)
    yield "dav_trashbin", c.dav("PROPFIND", f"/trashbin/{q}/", None, depth=0)
    yield "dav_versions", c.dav("PROPFIND", f"/versions/{q}/", None, depth=0)
    yield "dav_calendar_inbox", c.dav("PROPFIND", f"/calendars/{q}/inbox/", None, depth=0)
    yield "reference_profile", c.ocs("GET", f"/references/resolve?reference={up.quote(BASE + '/u/' + uid, safe='')}")
    yield "talk_create_1to1_probe", None  # couvert par l'audit d'actions (effet de bord)


def is_ok(r):
    return r is not None and r.status_code < 400


def run():
    clients = {u: Client(u) for u in ACTORS}
    import re as _re
    for u, c in clients.items():
        c.dav("PUT", f"/files/{u}/cloisons-test-disco.txt", b"x", ctype="text/plain")
        m = _re.search(r"<oc:fileid>(\d+)</oc:fileid>", c.dav("PROPFIND", f"/files/{u}/cloisons-test-disco.txt",
            '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>', depth=0).text)
        if m:
            OWN_FILE[u] = m.group(1)

    # 1) recherches
    for a in ACTORS:
        c = clients[a]
        for t in [u for u in USERS if u != a]:
            for term in terms_for(t):
                for vec, r in search_vectors(c, term):
                    if r is None:
                        continue
                    leaks = find_leaks(a, r.text, echo=(term,))
                    if leaks:
                        rec("LEAK", vec, a, {"term": term, "status": r.status_code, "leaks": sorted(set(leaks))})
                    if t in visible_for(a) and term in (USERS[t][0], t) and vec in (
                            "sharees", "autocomplete[files]", "contactsmenu", "cal_attendee",
                            "dav_principal_search", "dav_cs_principal_search"):
                        if not found_any(r.text.replace(term, ""), t) and t not in r.text:
                            rec("MISS", vec, a, {"term": term, "target": t, "status": r.status_code})

    # 2) listes
    for a in ACTORS:
        for vec, r in list_vectors(clients[a]):
            leaks = find_leaks(a, r.text)
            if leaks:
                rec("LEAK", vec, a, {"status": r.status_code, "leaks": sorted(set(leaks))})

    # 3) cibles + oracles (compte caché existant vs inexistant)
    for a in ACTORS:
        c = clients[a]
        ghost = dict(target_vectors(c, GHOST))
        # compte inexistant de MÊME FORME (sans tiret) pour les cibles en \w+ :
        # Nextcloud traite les identifiants selon leur forme (aperçu de profil)
        ghost_w = dict(target_vectors(c, GHOST.replace("-", "")))
        for t in hidden_for(a):
            for vec, r in target_vectors(c, t):
                if r is None:
                    continue
                leaks = find_leaks(a, r.text, echo=(t,))
                if leaks:
                    rec("LEAK", vec, a, {"target": t, "status": r.status_code, "leaks": sorted(set(leaks))})
                gw = _re.fullmatch(r"\w+", t) is not None
                g = (ghost_w if gw else ghost).get(vec)
                if g is not None and norm(r, t) != norm(g, GHOST.replace("-", "") if gw else GHOST):
                    rec("ORACLE", vec, a, {"target": t, "status": r.status_code, "ghost_status": g.status_code})
        # fonctionnement : soi-même et la même cloison restent accessibles
        for t in visible_for(a):
            for vec, r in target_vectors(c, t):
                if r is None or vec.startswith("dav_") or vec in ("cloud_user_fields", "identityproof",
                        "ooo", "ooo_now", "appointments_page", "e2ee_pubkey", "talk_proxy_avatar",
                        "cloud_user_subadmins_details", "cloud_user_groups", "cloud_user_groups_details",
                        "avatar_nofallback", "onlyoffice_userinfo"):
                    continue
                if not is_ok(r):
                    rec("MISS", vec, a, {"target": t, "status": r.status_code})


if __name__ == "__main__":
    run()
    out = sys.argv[1] if len(sys.argv) > 1 else "out/discovery.json"
    import os
    os.makedirs(os.path.dirname(out) or ".", exist_ok=True)
    from lib import GHOST_SEEN
    for g in GHOST_SEEN:
        RESULTS.append({"kind": "GHOSTLEAK", "vector": g["method"] + " " + g["path"], "actor": g["actor"], "detail": g})
    json.dump(RESULTS, open(out, "w"), indent=1, ensure_ascii=False)
    from collections import Counter
    print(Counter(r["kind"] for r in RESULTS))
    agg = Counter((r["kind"], r["vector"], "famille" if ZONE[r["actor"]] == "famille" else "client") for r in RESULTS)
    for (k, v, z), n in sorted(agg.items()):
        print(f"{k:7} {z:8} {v:40} {n}")
