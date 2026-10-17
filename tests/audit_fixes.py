#!/usr/bin/env python3
"""3e série (banc, comptes fictifs) : correctifs de l'audit du 2026-10-16.

Chaque vérification compare, quand c'est possible, un compte CACHÉ à un compte
INEXISTANT (aucun oracle) et contrôle la non-régression côté Famille.
Sortie : liste JSON de {verdict: PASS|FAIL, test, detail}.
"""
import json
import re
import subprocess
import sys
import time
import uuid

import requests

from lib import APPPW, BASE, BENCH, CONF, GHOST_PREFIX, GHOST_SEEN, USERS, Client

RUN = "cloisons-test-" + uuid.uuid4().hex[:6]
A, B, F, V = "client-aaaaaaaa", "client-bbbbbbbb", "fam-anne", "fam-paul"
NX = "client-z" + uuid.uuid4().hex[:7]          # compte inexistant
NXMAIL = NX + "@example.test"
OUT = []


def rec(ok, test, detail=""):
    OUT.append({"verdict": "PASS" if ok else "FAIL", "test": test, "detail": detail if not ok else ""})
    print(("PASS " if ok else "FAIL ") + test + ("" if ok else "  " + str(detail)[:300]), flush=True)


def leaks(text, *uids, typed=False):
    """Marqueurs d'un compte caché. typed=True : l'auteur a tapé l'identifiant
    lui-même (mention) ; son écho n'est pas une fuite, seuls nom et e-mail comptent."""
    found = []
    for u in uids:
        dn, mail = USERS[u]
        for m in ((dn, mail) if typed else (u, dn, mail)):
            if m and m in text:
                found.append(m)
    return found


def db(sql):
    env = dict(l.split("=", 1) for l in open(BENCH + "/.env").read().split())
    r = subprocess.run(["docker", "exec", "-e", "MYSQL_PWD=" + env["DBROOT"], "cloisons-banc-db-1", "mariadb", "-uroot",
                        "-N", "nextcloud", "-e", sql], capture_output=True, text=True)
    return r.stdout.strip()


def browser(uid):
    """Session navigateur réelle (formulaire de connexion) : cookie de session."""
    s = requests.Session()
    r = s.get(BASE + "/login", timeout=30)
    tok = re.search(r'data-requesttoken="([^"]+)"', r.text) or re.search(r'"requesttoken"\s*:\s*"([^"]+)"', r.text)
    # NC 34 exige l'en-tête Origin sur le formulaire (anti-connexion forcée), comme un navigateur
    s.post(BASE + "/login", data={"user": uid, "password": open(CONF + "/testpw").read().strip(),
                                  "requesttoken": tok.group(1) if tok else ""}, headers={"Origin": BASE},
           timeout=30, allow_redirects=True)
    me = s.get(BASE + "/ocs/v2.php/cloud/user?format=json", headers={"OCS-APIRequest": "true"}, timeout=30)
    assert me.status_code == 200 and uid in me.text, f"connexion navigateur impossible pour {uid}"
    return s


c = {u: Client(u) for u in (A, B, F, V)}

# ------------------------------------------------------------ 1. DAV hérité
for svc in ("caldav", "carddav", "calendar", "contacts"):
    for m in ("PROPFIND", "REPORT", "GET"):
        r = requests.request(m, f"{BASE}/remote.php/{svc}/", auth=(A, APPPW[A]), timeout=30)
        rec(r.status_code == 404, f"legacy:{svc}:{m}", r.status_code)

# ------------------------------------------- 2. partage d'agenda encodé (A → F)
cal = RUN + "-cal"
c[A].dav("MKCALENDAR", f"/calendars/{A}/{cal}/",
         f'<?xml version="1.0"?><c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:set><d:prop>'
         f'<d:displayname>{cal}</d:displayname></d:prop></d:set></c:mkcalendar>')


def share_body(href):
    return ('<?xml version="1.0" encoding="utf-8"?><o:share xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns">'
            f'<o:set><d:href>{href}</d:href><o:read-only/></o:set></o:share>')


def shared_to(owner_cal_id_like, target):
    return db(f"select count(*) from oc_dav_shares s join oc_calendars c on c.id=s.resourceid "
              f"where s.type='calendar' and c.uri='{owner_cal_id_like}' and s.principaluri='principals/users/{target}'")


variants = {
    "plain": f"principal:principals/users/{F}",
    "entity": "principal:principals/users/&#102;am-anne",
    "hexentity": "principal:principals/users/&#x66;am-anne",
    "cdata": f"<![CDATA[principal:principals/users/{F}]]>",
    "pct": "principal:principals/users/fam%2Danne",
    "mailto": "mailto:" + USERS[F][1],
    "mailto-entity": "mailto:fam-anne&#64;example.test",
    "mailto-case": "MAILTO:FAM-ANNE@EXAMPLE.TEST",
}
for name, href in variants.items():
    r = c[A].dav("POST", f"/calendars/{A}/{cal}/", share_body(href))
    rec(shared_to(cal, F) == "0", f"cal-share-crossed:{name}", (r.status_code, shared_to(cal, F)))
dtd = lambda who: ('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY u "' + who + '">]><o:share xmlns:d="DAV:" '
                   'xmlns:o="http://owncloud.org/ns"><o:set><d:href>principal:principals/users/&u;</d:href></o:set></o:share>')
r1, r2 = c[A].dav("POST", f"/calendars/{A}/{cal}/", dtd(F)), c[A].dav("POST", f"/calendars/{A}/{cal}/", dtd(NX))
rec(r1.status_code == r2.status_code and shared_to(cal, F) == "0", "cal-share:dtd-refused-like-nonexistent", (r1.status_code, r2.status_code))
rec(leaks(c[A].dav("PROPFIND", f"/calendars/{A}/{cal}/",
                   '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns"><d:prop><o:invite/></d:prop></d:propfind>',
                   depth=0).text, F, V) == [], "cal-share:invite-no-leak")
# non-régression Famille : F partage avec V, y compris en écriture encodée
fcal = RUN + "-fcal"
c[F].dav("MKCALENDAR", f"/calendars/{F}/{fcal}/",
         f'<?xml version="1.0"?><c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:set><d:prop>'
         f'<d:displayname>{fcal}</d:displayname></d:prop></d:set></c:mkcalendar>')
c[F].dav("POST", f"/calendars/{F}/{fcal}/", share_body("principal:principals/users/&#102;am-paul"))
rec(shared_to(fcal, V) == "1", "famille:cal-share-encoded-ok", shared_to(fcal, V))
c[F].dav("POST", f"/calendars/{F}/{fcal}/", share_body("principal:principals/groups/cloison-famille"))
rec(db(f"select count(*) from oc_dav_shares s join oc_calendars c on c.id=s.resourceid where c.uri='{fcal}' "
       "and s.principaluri='principals/groups/cloison-famille'") == "1", "famille:cal-share-group-ok")

# ------------------------------------------- 3. partage de carnet encodé (A → F)
ab = RUN + "-ab"
c[A].dav("MKCOL", f"/addressbooks/users/{A}/{ab}/",
         '<?xml version="1.0"?><d:mkcol xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:set><d:prop>'
         f'<d:resourcetype><d:collection/><c:addressbook/></d:resourcetype><d:displayname>{ab}</d:displayname>'
         '</d:prop></d:set></d:mkcol>')
for name in ("plain", "entity", "cdata", "mailto-entity"):
    c[A].dav("POST", f"/addressbooks/users/{A}/{ab}/", share_body(variants[name]))
    n = db(f"select count(*) from oc_dav_shares s join oc_addressbooks a on a.id=s.resourceid "
           f"where s.type='addressbook' and a.uri='{ab}' and s.principaluri='principals/users/{F}'")
    rec(n == "0", f"ab-share-crossed:{name}", n)

# ------------------------------------------- 4. disponibilités (repli de ligne)
ev = (f"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//FR\r\nBEGIN:VEVENT\r\nUID:{RUN}-ev\r\nDTSTAMP:20261016T080000Z\r\n"
      "DTSTART:20261110T090000Z\r\nDTEND:20261110T100000Z\r\nSUMMARY:occupe\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")
c[F].dav("PUT", f"/calendars/{F}/personal/{RUN}-ev.ics", ev, ctype="text/calendar; charset=utf-8")


def freebusy(actor, attendee_line):
    body = (f"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//FR\r\nMETHOD:REQUEST\r\nBEGIN:VFREEBUSY\r\nUID:{RUN}-fb\r\n"
            "DTSTAMP:20261016T080000Z\r\nDTSTART:20261101T000000Z\r\nDTEND:20261201T000000Z\r\n"
            f"ORGANIZER:mailto:{USERS[actor][1]}\r\n{attendee_line}\r\nEND:VFREEBUSY\r\nEND:VCALENDAR\r\n")
    return c[actor].dav("POST", f"/calendars/{actor}/outbox/", body, ctype="text/calendar; charset=utf-8")


def fb_norm(t):
    return re.sub(r"DTSTAMP:\S+|UID:\S+|mailto:[^\s<]+|zcl0\w+", "*", t)


fam = USERS[F][1]
for name, line in {
    "plain": f"ATTENDEE:mailto:{fam}",
    "folded": "ATTENDEE:mailto:fam-an\r\n ne@example.test",
    "folded-tab": "ATTENDEE:mailto:fam-a\r\n\tnne@example.test",
    "upper": f"ATTENDEE:MAILTO:{fam.upper()}",
}.items():
    r1 = freebusy(A, line)
    r2 = freebusy(A, line.replace("fam-an\r\n ne", NX).replace("fam-a\r\n\tnne", NX).replace(fam, NXMAIL).replace(fam.upper(), NXMAIL.upper()))
    busy = "FREEBUSY" in r1.text and "20261110T090000Z" in r1.text
    rec(not busy and r1.status_code == r2.status_code and fb_norm(r1.text) == fb_norm(r2.text),
        f"freebusy:{name}", (r1.status_code, r2.status_code, r1.text[:300]))
r = freebusy(V, f"ATTENDEE:mailto:{fam}")
rec("20261110T090000Z" in r.text, "famille:freebusy-ok", r.text[:200])

# ------------------------------------------- 5. mentions Talk
def room(actor):
    r = c[actor].ocs("POST", "/apps/spreed/api/v4/room", json={"roomType": 2, "roomName": RUN + "-" + actor[:5]})
    return r.json()["ocs"]["data"]["token"]


def say(actor, token, text):
    r = c[actor].ocs("POST", f"/apps/spreed/api/v1/chat/{token}", json={"message": text})
    return r.json()["ocs"]["data"] if r.status_code < 300 else {"error": r.status_code}


ta = room(A)
for form in ("@fam-anne", '@"fam-anne"', "@fam-paul"):
    tgt = "fam-paul" if "paul" in form else "fam-anne"
    m1 = say(A, ta, f"{form} bonjour")
    m2 = say(A, ta, f"{form.replace(tgt, NX)} bonjour")
    same = (m1.get("message", "").replace(tgt, "<U>") == m2.get("message", "").replace(NX, "<U>")
            and not m1.get("messageParameters") and not m2.get("messageParameters"))
    rec(same and not leaks(json.dumps(m1), F, V, typed=True), f"talk-mention:{form}",
        (m1.get("message"), m1.get("messageParameters"), m2.get("message"), m2.get("messageParameters")))
hist = c[A].ocs("GET", f"/apps/spreed/api/v1/chat/{ta}?lookIntoFuture=0&limit=50").text
rec(not leaks(hist, F, V, typed=True), "talk-mention:history", leaks(hist, F, V, typed=True))
rooms = c[A].ocs("GET", "/apps/spreed/api/v4/room").text
rec(not leaks(rooms, F, V, typed=True), "talk-mention:room-list-lastMessage", leaks(rooms, F, V, typed=True))
tf = room(F)
m = say(F, tf, "@fam-paul bonjour")
rec("Famille Paul" in json.dumps(m), "famille:talk-mention-ok", m.get("messageParameters"))
m = say(A, ta, "@client-aaaaaaaa moi")
rec("Alice Client" in json.dumps(m), "client:talk-self-mention-ok", m.get("messageParameters"))

# ------------------------------------------- 6. mentions de commentaire + 7. Activité
def upload(actor, name):
    c[actor].dav("PUT", f"/files/{actor}/{name}", "contenu de test", ctype="text/plain")
    r = c[actor].dav("PROPFIND", f"/files/{actor}/{name}",
                     '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns"><d:prop><o:fileid/></d:prop></d:propfind>', depth=0)
    return re.search(r"<oc:fileid>(\d+)</oc:fileid>", r.text).group(1)


def comment(actor, fid, text):
    c[actor].dav("POST", f"/comments/files/{fid}", json.dumps({"actorType": "users", "verb": "comment", "message": text,
                                                                 "objectType": "files"}), ctype="application/json")
    r = c[actor].dav("REPORT", f"/comments/files/{fid}",
                     '<?xml version="1.0"?><oc:filter-comments xmlns:oc="http://owncloud.org/ns"><oc:limit>50</oc:limit></oc:filter-comments>')
    return r.text


fa = upload(A, RUN + "-a.txt")
t1 = comment(A, fa, "@fam-anne regarde")
t2 = comment(A, fa, f"@{NX} regarde")
dn = lambda t, uid: re.findall(rf"<oc:mentionId>{re.escape(uid)}</oc:mentionId>\s*<oc:mentionDisplayName>([^<]*)<", t)
rec(not leaks(t1, F, V, typed=True) and dn(t1, F) and dn(t1, F) == dn(t2, NX), "comment-mention", (dn(t1, F), dn(t2, NX)))
time.sleep(2)
act = c[A].ocs("GET", "/apps/activity/api/v2/activity/all?limit=100").text
rec(not leaks(act, F, V, typed=True), "activity-mention", leaks(act, F, V, typed=True))
act1 = c[A].ocs("GET", "/apps/activity/api/v2/activity/filter?filter=all&limit=100").text
rec(not leaks(act1, F, V, typed=True), "activity-mention:filter", leaks(act1, F, V, typed=True))
ff = upload(F, RUN + "-f.txt")
t = comment(F, ff, "@fam-paul regarde")
rec("Famille Paul" in t, "famille:comment-mention-ok")
time.sleep(2)
rec("Famille Paul" in c[F].ocs("GET", "/apps/activity/api/v2/activity/all?limit=100").text, "famille:activity-mention-ok")

# ------------------------------------------- 8. Bookmarks (F administrateur)
r = c[F].req("POST", "/index.php/apps/bookmarks/folder", csrf=True, json={"title": RUN + "-bm", "parent_folder": -1})
try:
    bm = r.json()["item"]["id"]
except Exception:
    bm = None
if bm:
    for who, typ in ((A, 0), ("Office", 1)):
        c[F].req("POST", f"/index.php/apps/bookmarks/folder/{bm}/shares", csrf=True, json={"participant": who, "type": typ})
        n = db(f"select count(*) from oc_bookmarks_shares where folder_id={bm} and participant='{who}'")
        rec(n == "0", f"bookmarks-share-crossed:{who}", n)
    rec(db("select count(*) from oc_bookmarks_shares where participant like 'zcl0%'") == "0", "bookmarks:no-ghost-rows")
    c[F].req("POST", f"/index.php/apps/bookmarks/folder/{bm}/shares", csrf=True, json={"participant": V, "type": 0})
    rec(db(f"select count(*) from oc_bookmarks_shares where folder_id={bm} and participant='{V}'") == "1", "famille:bookmarks-share-ok")
    r = c[A].req("GET", "/index.php/apps/bookmarks/public/rest/v2/folder")
    rec(r.status_code in (401, 403, 404), "bookmarks:client-no-access", r.status_code)
else:
    rec(False, "bookmarks:setup", r.status_code)

# ------------------------------------------- 9. équipes inexistantes : aucun fantôme
before = db("select count(*) from oc_cloisons_ghosts")
for i in range(5):
    cid = uuid.uuid4().hex[:31]
    c[A].req("GET", f"/index.php/apps/calendar/v1/circles/getmembers?circleId={cid}", csrf=True)
    c[A].ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + RUN + "-a.txt", "shareType": 7, "shareWith": cid})
rec(db("select count(*) from oc_cloisons_ghosts") == before, "ghosts:no-growth-on-random-team", (before, db("select count(*) from oc_cloisons_ghosts")))

# ------------------------------------------- 10. liens publics de l'Agenda
pub = RUN + "-pub"
c[F].dav("MKCALENDAR", f"/calendars/{F}/{pub}/",
         f'<?xml version="1.0"?><c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:set><d:prop>'
         f'<d:displayname>{pub}</d:displayname></d:prop></d:set></c:mkcalendar>')
c[F].dav("POST", f"/calendars/{F}/{pub}/", '<?xml version="1.0"?><cs:publish-calendar xmlns:cs="http://calendarserver.org/ns/"/>')
r = c[F].dav("PROPFIND", f"/calendars/{F}/{pub}/",
             '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:prop><cs:publish-url/></d:prop></d:propfind>', depth=0)
m = re.search(r"public-calendars/([^/<]+)", r.text)
if m:
    tok = m.group(1)
    fake = "zzpubfake" + uuid.uuid4().hex[:8]
    sa, sf, sv = browser(A), browser(F), browser(V)
    pg = lambda s_, t: s_.get(f"{BASE}/index.php/apps/calendar/p/{t}", timeout=30)
    norm_ = lambda r, t: (r.status_code, re.sub(r"[0-9a-f]{20,}|nonce=\"[^\"]*\"|data-requesttoken=\"[^\"]*\"", "*", r.text.replace(t, "<T>")))
    ra, rx = pg(sa, tok), pg(sa, fake)
    rec(norm_(ra, tok) == norm_(rx, fake), "pubcal:page-client-like-nonexistent", (ra.status_code, rx.status_code))
    rec(pg(sv, tok).status_code == 200 and pg(sf, tok).status_code == 200, "famille:pubcal-page-ok")
    pd = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>'
    dv = lambda s_, t, auth=None: s_.request("PROPFIND", f"{BASE}/remote.php/dav/public-calendars/{t}/", data=pd,
                                             headers={"Depth": "0"}, timeout=30)
    da, dx = dv(sa, tok), dv(sa, fake)
    rec(pub not in da.text and da.status_code == dx.status_code, "pubcal:dav-client-like-nonexistent", (da.status_code, dx.status_code, da.text[:150]))
    rec(pub in dv(requests, tok).text, "pubcal:dav-anonymous-still-works")
    rec(pub in dv(sv, tok).text, "famille:pubcal-dav-ok")
else:
    rec(False, "pubcal:setup", r.text[:200])

# rendez-vous : configuration publique de F
r = c[F].req("POST", "/index.php/apps/calendar/v1/appointment_configs", csrf=True, json={
    "name": RUN + "-rdv", "description": "", "location": "", "visibility": "PUBLIC", "targetCalendarUri": "personal",
    "availability": {"timezoneId": "Europe/Paris", "slots": {d: [{"start": 1700000000, "end": 1700030000}] for d in
                                                              ("MO", "TU", "WE", "TH", "FR", "SA", "SU")}},
    "length": 1800, "increment": 1800, "preparationDuration": 0, "followupDuration": 0, "timeBeforeNextSlot": 0,
    "calendarFreeBusyUris": []})
try:
    atok = r.json()["data"]["token"]
except Exception:
    atok = None
if atok:
    fake = "zzrdvfake" + uuid.uuid4().hex[:8]
    sa, sv = browser(A), browser(V)
    ap = lambda s_, t: s_.get(f"{BASE}/index.php/apps/calendar/appointment/{t}", timeout=30)
    norm_ = lambda r, t: (r.status_code, re.sub(r"[0-9a-f]{20,}|nonce=\"[^\"]*\"|data-requesttoken=\"[^\"]*\"", "*", r.text.replace(t, "<T>")))
    rec(norm_(ap(sa, atok), atok) == norm_(ap(sa, fake), fake), "rdv:page-client-like-nonexistent", (ap(sa, atok).status_code, ap(sa, fake).status_code))
    rec(ap(sv, atok).status_code == 200 and ap(sv, atok).text != ap(sv, fake).text, "famille:rdv-page-ok")
    rec(ap(requests, atok).text != ap(requests, fake).text, "rdv:anonymous-still-works")
    sl = lambda s_, t: s_.get(f"{BASE}/index.php/apps/calendar/appointment/{t}/slots?dateSelected=2026-11-10&timeZone=Europe/Paris", timeout=30)
    r1, r2 = sl(sa, atok), sl(sa, fake)
    rec((r1.status_code, r1.text) == (r2.status_code, r2.text), "rdv:slots-client-like-nonexistent", (r1.status_code, r2.status_code, r1.text[:120]))
else:
    rec(False, "rdv:setup", (r.status_code, r.text[:200]))

# ------------------------------------------- 11. liens publics WebDAV
c[F].dav("MKCOL", f"/files/{F}/{RUN}-dir")
r = c[F].ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": f"/{RUN}-dir", "shareType": 3, "permissions": 15})
try:
    ltok = r.json()["ocs"]["data"]["token"]
except Exception:
    ltok = None
if ltok:
    sa, sv = browser(A), browser(V)
    pd = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>'
    # public.php force le mode anonyme : un compte connecté y est un invité anonyme (instance dédiée)
    for label, url, extra in (("v2", f"{BASE}/public.php/dav/files/{ltok}/", {}),
                              ("v1", f"{BASE}/public.php/webdav/", {"auth": (ltok, "")})):
        ra = sa.request("PROPFIND", url, data=pd, headers={"Depth": "1"}, timeout=30, **extra)
        rn = requests.request("PROPFIND", url, data=pd, headers={"Depth": "1"}, timeout=30, **extra)
        rv = sv.request("PROPFIND", url, data=pd, headers={"Depth": "1"}, timeout=30, **extra)
        rec(ra.status_code == rn.status_code == 207, f"pubdav:{label}:client-connected-as-anonymous", (ra.status_code, rn.status_code))
        rec(rv.status_code == 207, f"famille:pubdav:{label}-ok", rv.status_code)
        rec(not leaks(ra.text, A), f"pubdav:{label}:client-identity-not-exposed", leaks(ra.text, A))
    # lien en lecture seule : le téléchargement reste permis (décision utilisateur)
    c[F].dav("PUT", f"/files/{F}/{RUN}-ro.txt", "lecture", ctype="text/plain")
    r = c[F].ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": f"/{RUN}-ro.txt", "shareType": 3, "permissions": 1})
    rtok = r.json()["ocs"]["data"]["token"]
    ra = browser(A).get(f"{BASE}/public.php/dav/files/{rtok}", timeout=30)
    rec(ra.status_code == 200 and ra.text == "lecture", "pubdav:readonly-file-download-allowed", ra.status_code)
else:
    rec(False, "pubdav:setup", r.text[:200])

# ------------------------------------------- 12. fonctions natives du client (aucune perte)
fa2 = RUN + "-a2.txt"
c[A].dav("PUT", f"/files/{A}/{fa2}", "x", ctype="text/plain")
r = c[A].ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + fa2, "shareType": 3})
rec(r.status_code == 200, "client:public-link-ok", r.status_code)
r = c[A].ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + fa2, "shareType": 4, "shareWith": "externe@example.org"})
rec(r.status_code == 200, "client:mail-share-ok", (r.status_code, r.text[:150]))
r = c[A].ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + fa2, "shareType": 0, "shareWith": B})
r2 = c[A].ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + fa2, "shareType": 0, "shareWith": NX})
rec(r.status_code == r2.status_code and r.status_code >= 400, "client:share-other-client-like-nonexistent", (r.status_code, r2.status_code))

# ------------------------------------------- fantômes visibles
rec(not GHOST_SEEN, "ghost-never-visible", GHOST_SEEN[:3])

json.dump(OUT, open(sys.argv[1] if len(sys.argv) > 1 else "out/fixes.json", "w"), indent=1, ensure_ascii=False)
print(f"FINI {sum(o['verdict'] == 'PASS' for o in OUT)}/{len(OUT)} PASS")
