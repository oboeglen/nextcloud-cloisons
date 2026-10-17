#!/usr/bin/env python3
"""Audit d'actions (2e série) et tests de fonctionnement.

Vecteurs : LibreSign, mentions Text/ONLYOFFICE, transfert de formulaire,
propositions de réunion, délégation d'agenda, participants Talk à la création,
invitation fédérée Talk, ajout multiple à une équipe.
Fonctionnement : chaque compte doit pouvoir tout faire dans sa cloison.
"""
import json
import os
import re
import sys
import time
import uuid

import requests

from lib import ACTORS, BASE, GHOST, HOST, USERS, ZONE, Client, norm

RUN = uuid.uuid4().hex[:6]
RESULTS = []
C = {}
GROUPS = {
    "fam-anne": {"admin", "Assistant IA", "E2EE", "Developpement", "Domotique", "IA", "Office", "Photos", "Medias", "Intranet", "Signatures", "Talk", "Coffre"},
    "fam-paul": {"Domotique", "IA", "Office", "Photos", "Medias", "Intranet", "Talk"},
    "client-aaaaaaaa": {"Office", "Photos", "Talk", "Signatures", "Coffre", "IA"},
    "client-bbbbbbbb": {"Office", "Photos", "Talk", "Signatures", "Coffre", "IA"},
    "client-cccccccc": {"Office"},
    "client-dddddddd": set(),
}


def rec(kind, vector, actor, detail=None):
    RESULTS.append({"kind": kind, "vector": vector, "actor": actor, "detail": detail or {}})


def cl(u):
    if u not in C:
        C[u] = Client(u)
    return C[u]


def data(r):
    try:
        return r.json()["ocs"]["data"]
    except Exception:
        return None


def notifs(u):
    return cl(u).ocs("GET", "/apps/notifications/api/v2/notifications").text


def put(c, name, content=b"cloisons", ctype="text/plain"):
    return c.dav("PUT", f"/files/{c.uid}/{name}", content, ctype=ctype).status_code in (201, 204)


def fileid(c, name):
    pf = c.dav("PROPFIND", f"/files/{c.uid}/{name}", '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>', depth=0)
    m = re.search(r"<oc:fileid>(\d+)</oc:fileid>", pf.text)
    return int(m.group(1)) if m else None


def oracle(vector, actor, fn, target):
    r1, r2 = fn(target), fn(GHOST)
    e1, e2 = USERS[target][1] or target, "ghost@example.test"
    if norm(r1, target, e1) != norm(r2, GHOST, e2):
        rec("ORACLE", vector, actor, {"target": target, "s1": r1.status_code, "s2": r2.status_code, "b1": r1.text[:300], "b2": r2.text[:300]})


def judge(vector, a, t, ok, arrived, r=None):
    if ok and not arrived:
        rec("BROKEN", vector, a, {"target": t, "status": getattr(r, "status_code", None), "body": getattr(r, "text", "")[:300]})
    if not ok and arrived:
        rec("CROSS", vector, a, {"target": t, "status": getattr(r, "status_code", None)})


MINI_PDF = (b"%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj "
            b"3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\nxref\n0 4\n0000000000 65535 f \n"
            b"0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n160\n%%EOF\n")


# ---------------------------------------------------------------- vecteurs
def v_libresign(a, t, ok):
    if "Signatures" not in GROUPS[a]:
        return
    c = cl(a)
    name = f"iso-{RUN}-{a}-sign-{tid(t)}.pdf"
    put(c, name, MINI_PDF, "application/pdf")
    fid = fileid(c, name)

    def req(target_value, method="account"):
        return c.ocs("POST", "/apps/libresign/api/v1/request-signature", json={
            "file": {"fileId": fid}, "name": name,
            "signers": [{"identifyMethods": [{"method": method, "value": target_value}], "displayName": "X"}]})
    if ok and "Signatures" not in GROUPS[t]:
        return
    r = req(t)
    time.sleep(0.5)
    lst = cl(t).ocs("GET", "/apps/libresign/api/v1/file/list").text if "Signatures" in GROUPS[t] else ""
    arrived = name in lst or name in notifs(t)
    judge("libresign_account", a, t, ok, arrived, r)
    if not ok:
        r2 = req(USERS[t][1], "email")
        if name in notifs(t) or name in (cl(t).ocs("GET", "/apps/libresign/api/v1/file/list").text if "Signatures" in GROUPS[t] else ""):
            rec("CROSS", "libresign_email", a, {"target": t, "status": r2.status_code})


def v_text_mention(a, t, ok):
    c = cl(a)
    name = f"iso-{RUN}-{a}-txt-{tid(t)}.md"
    put(c, name, b"# test\n", "text/markdown")
    fid = fileid(c, name)
    s = c.req("PUT", f"/index.php/apps/text/session/{fid}/create", csrf=True, json={"fileId": fid})
    try:
        sd = s.json()
        doc, sid, stok = sd["document"]["id"], sd["session"]["id"], sd["session"]["token"]
    except Exception:
        rec("BROKEN", "text_session_create", a, {"status": s.status_code, "body": s.text[:200]})
        return
    if ok:
        # dans la cloison : le fichier est d'abord partagé, la mention doit être notifiée
        c.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + name, "shareType": 0, "shareWith": t})
    fn = lambda u: c.req("PUT", f"/index.php/apps/text/session/{doc}/mention", csrf=True,
                         json={"documentId": doc, "sessionId": sid, "sessionToken": stok, "mention": u})
    r = fn(t)
    time.sleep(0.3)
    # notification Text de CE fichier, venant de CET auteur (et non d'une mention légitime ailleurs)
    try:
        mine = [n for n in cl(t).ocs("GET", "/apps/notifications/api/v2/notifications").json()["ocs"]["data"]
                if n.get("app") == "text" and str(n.get("object_id")) == str(fid)]
    except Exception:
        mine = []
    judge("text_mention", a, t, ok, bool(mine), r)
    if not ok:
        # oracle symétrique : 1re mention du compte caché contre 1re mention d'un compte inexistant
        # (Text renvoie false à la 2e mention d'une même personne dans un document)
        r2 = fn(GHOST)
        if norm(r, t, USERS[t][1] or t) != norm(r2, GHOST, "ghost@example.test"):
            rec("ORACLE", "text_mention", a, {"target": t, "s1": r.status_code, "s2": r2.status_code, "b1": r.text[:300], "b2": r2.text[:300]})


def v_onlyoffice_mention(a, t, ok):
    if "Office" not in GROUPS[a]:
        return
    c = cl(a)
    name = f"iso-{RUN}-{a}-oo-{tid(t)}.docx"
    put(c, name, b"PK\x03\x04", "application/vnd.openxmlformats-officedocument.wordprocessingml.document")
    fid = fileid(c, name)
    fn = lambda e: c.req("POST", "/index.php/apps/onlyoffice/ajax/mention", csrf=True,
                         json={"fileId": fid, "anchor": "{}", "comment": "hello", "emails": [e]})
    r = fn(USERS[t][1])
    time.sleep(0.3)
    arrived = name in notifs(t) or name in cl(t).ocs("GET", "/apps/files_sharing/api/v1/shares?shared_with_me=true").text
    if not ok and arrived:
        rec("CROSS", "onlyoffice_mention", a, {"target": t, "status": r.status_code})
    if not ok:
        r1, r2 = fn(USERS[t][1]), fn("ghost@example.test")
        if (r1.status_code, r1.text.replace(USERS[t][1], "M")) != (r2.status_code, r2.text.replace("ghost@example.test", "M")):
            rec("ORACLE", "onlyoffice_mention", a, {"target": t, "b1": r1.text[:200], "b2": r2.text[:200]})


def v_forms_transfer(a, t, ok):
    c = cl(a)
    r = c.ocs("POST", "/apps/forms/api/v3/forms")
    fid = (data(r) or {}).get("id") if isinstance(data(r), dict) else None
    if not fid:
        return
    title = f"iso-{RUN}-{a}-xf-{tid(t)}"
    c.ocs("PATCH", f"/apps/forms/api/v3/forms/{fid}", json={"keyValuePairs": {"title": title}})
    fn = lambda u: c.ocs("PATCH", f"/apps/forms/api/v3/forms/{fid}", json={"keyValuePairs": {"ownerId": u}})
    if not ok:
        oracle("forms_transfer", a, fn, t)
    r2 = fn(t)
    arrived = title in cl(t).ocs("GET", "/apps/forms/api/v3/forms?type=owned").text
    judge("forms_transfer", a, t, ok, arrived, r2)


def v_proposal(a, t, ok):
    c = cl(a)
    title = f"iso-{RUN}-{a}-prop-{tid(t)}"
    body = {"proposal": {"@type": "MeetingProposal", "title": title, "description": "x", "location": "",
                         "duration": 60,
                         "participants": [{"@type": "MeetingProposalParticipant", "name": "X", "address": "mailto:" + USERS[t][1],
                                           "attendance": "R", "realm": "I", "status": "P"}],
                         "dates": [{"@type": "MeetingProposalDate", "date": "2026-12-10T10:00:00Z"}]}}
    r = c.ocs("POST", "/calendar/proposal/create", json=body)
    time.sleep(0.5)
    rep = cl(t).dav("REPORT", f"/calendars/{t}/personal/", '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"/></c:filter></c:calendar-query>', depth=1).text
    lst = cl(t).ocs("POST", "/calendar/proposal/list", json={}).text
    arrived = title in rep or title in notifs(t) or title in lst
    if r.status_code >= 400:
        rec("INFO", "proposal_create_failed", a, {"status": r.status_code, "body": r.text[:200]})
        return
    judge("calendar_proposal", a, t, ok, arrived, r)


def v_proxy(a, t, ok):
    c = cl(a)
    body = f'<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:"><d:set><d:prop><d:group-member-set><d:href>/remote.php/dav/principals/users/{{u}}/</d:href></d:group-member-set></d:prop></d:set></d:propertyupdate>'
    fn = lambda u: c.dav("PROPPATCH", f"/principals/users/{a}/calendar-proxy-read/", body.replace("{u}", u))
    r = fn(t)
    pf = cl(t).dav("PROPFIND", f"/principals/users/{t}/", '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:prop><cs:calendar-proxy-read-for/><cs:calendar-proxy-write-for/></d:prop></d:propfind>', depth=0).text
    arrived = f"principals/users/{a}" in pf
    judge("calendar_proxy", a, t, ok, arrived, r)
    if not ok:
        oracle("calendar_proxy", a, fn, t)
    c.dav("PROPPATCH", f"/principals/users/{a}/calendar-proxy-read/", '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:"><d:set><d:prop><d:group-member-set/></d:prop></d:set></d:propertyupdate>')


def v_talk_create_participants(a, t, ok):
    if "Talk" not in GROUPS[a] and "admin" not in GROUPS[a]:
        return
    c = cl(a)
    r = c.ocs("POST", "/apps/spreed/api/v4/room", json={"roomType": 2, "roomName": f"iso-{RUN}-{a}-p-{tid(t)}", "participants": {"users": [t]}})
    d = data(r)
    tok = d.get("token") if isinstance(d, dict) else None
    arrived = bool(tok) and tok in cl(t).ocs("GET", "/apps/spreed/api/v4/room").text
    judge("talk_create_participants", a, t, ok, arrived, r)
    if not ok:
        for host in (HOST, "localhost"):
            r2 = c.ocs("POST", f"/apps/spreed/api/v4/room/{tok}/participants", data={"newParticipant": f"{t}@{host}", "source": "federated_users"}) if tok else None
            if tok and (tok in cl(t).ocs("GET", "/apps/spreed/api/v4/room").text or "invitation" in cl(t).ocs("GET", "/apps/spreed/api/v1/federation/invitation").text.lower() and a in cl(t).ocs("GET", "/apps/spreed/api/v1/federation/invitation").text):
                rec("CROSS", "talk_federated_invite", a, {"target": t, "host": host, "status": r2.status_code if r2 is not None else None})


def v_circle_multi(a, t, ok):
    c = cl(a)
    r = c.ocs("POST", "/apps/circles/circles", json={"name": f"iso-{RUN}-{a}-m-{tid(t)}"})
    cid = (data(r) or {}).get("id") if isinstance(data(r), dict) else None
    if not cid:
        return
    r2 = c.ocs("POST", f"/apps/circles/circles/{cid}/members/multi", json={"members": [{"id": t, "type": 1}]})
    arrived = cid in cl(t).ocs("GET", "/apps/circles/circles").text
    judge("circle_add_multi", a, t, ok, arrived, r2)


def v_public_links(owner, a, ok):
    """`owner` publie des liens publics ; `a`, connecté, tente de les utiliser."""
    co, ca = cl(owner), cl(a)
    # lien de fichier en lecture seule : toujours consultable
    name = f"iso-{RUN}-pub-{tid(owner)}-{tid(a)}.txt"
    put(co, name, b"public")
    r = co.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + name, "shareType": 3, "permissions": 1})
    tok = (data(r) or {}).get("token") if isinstance(data(r), dict) else None
    if tok:
        v = ca.req("GET", f"/index.php/s/{tok}")
        if v.status_code != 200:
            rec("BROKEN", "public_readonly_link_logged_in", a, {"owner": owner, "status": v.status_code})
        ts = ca.req("PUT", f"/index.php/apps/text/public/session/1/create", csrf=True, json={"token": tok, "guestName": "x"})
        if not ok and ts.status_code == 200 and "session" in ts.text:
            rec("CROSS", "public_readonly_link_editor_session", a, {"owner": owner})
    # lien de dossier modifiable (dépôt) : refusé hors cloison si connecté
    folder = f"iso-{RUN}-drop-{tid(owner)}-{tid(a)}"
    co.dav("MKCOL", f"/files/{owner}/{folder}")
    r = co.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + folder, "shareType": 3, "permissions": 15})
    tok = (data(r) or {}).get("token") if isinstance(data(r), dict) else None
    if tok:
        v = ca.req("GET", f"/index.php/s/{tok}")
        if ok and v.status_code != 200:
            rec("BROKEN", "public_editable_link_same_zone", a, {"owner": owner, "status": v.status_code})
        if not ok and v.status_code == 200:
            rec("CROSS", "public_editable_link_logged_in", a, {"owner": owner})
        if not ok:
            ghost = ca.req("GET", "/index.php/s/AAAAAAAAAAAAAAA")
            if v.status_code != ghost.status_code:
                rec("ORACLE", "public_editable_link_logged_in", a, {"owner": owner, "s1": v.status_code, "s2": ghost.status_code})
        anon = requests.get(f"{BASE}/index.php/s/{tok}", timeout=30)
        if anon.status_code != 200:
            rec("BROKEN", "public_editable_link_anonymous", a, {"owner": owner, "status": anon.status_code})
    # conversation Talk publique
    if "Talk" in GROUPS[owner] or "admin" in GROUPS[owner]:
        r = co.ocs("POST", "/apps/spreed/api/v4/room", data={"roomType": 3, "roomName": f"iso-{RUN}-pubroom"})
        ptok = (data(r) or {}).get("token") if isinstance(data(r), dict) else None
        if ptok and ("Talk" in GROUPS[a] or "admin" in GROUPS[a]):
            j = ca.ocs("POST", f"/apps/spreed/api/v4/room/{ptok}/participants/active", data={})
            if not ok and j.status_code == 200:
                rec("CROSS", "public_talk_room_logged_in", a, {"owner": owner})
            if ok and j.status_code != 200:
                rec("BROKEN", "public_talk_room_same_zone", a, {"owner": owner, "status": j.status_code})
            if not ok:
                g = ca.ocs("POST", "/apps/spreed/api/v4/room/zzzzzzzzzz/participants/active", data={})
                if (j.status_code, j.text.replace(ptok, "T")) != (g.status_code, g.text.replace("zzzzzzzzzz", "T")):
                    rec("ORACLE", "public_talk_room_logged_in", a, {"owner": owner, "b1": j.text[:200], "b2": g.text[:200]})
    # formulaire public
    r = co.ocs("POST", "/apps/forms/api/v3/forms")
    fid = (data(r) or {}).get("id") if isinstance(data(r), dict) else None
    if fid:
        q = co.ocs("POST", f"/apps/forms/api/v3/forms/{fid}/questions", json={"type": "short", "text": "Q"})
        qid = (data(q) or {}).get("id") if isinstance(data(q), dict) else None
        sh = co.ocs("POST", f"/apps/forms/api/v3/forms/{fid}/shares", data={"shareType": 3})
        h = (data(sh) or {}).get("shareWith") if isinstance(data(sh), dict) else None
        if h and qid:
            sub = ca.ocs("POST", f"/apps/forms/api/v3/forms/{fid}/submissions", json={"answers": {str(qid): ["x"]}, "shareHash": h})
            if not ok and sub.status_code in (200, 201):
                rec("CROSS", "public_form_logged_in", a, {"owner": owner})
            if ok and sub.status_code not in (200, 201):
                rec("BROKEN", "public_form_same_zone", a, {"owner": owner, "status": sub.status_code, "body": sub.text[:200]})


# ----------------------------------------------------------- fonctionnement
def self_tests(a):
    c = cl(a)
    g = GROUPS[a]
    # fichiers + lien public
    name = f"iso-{RUN}-{a}-self.txt"
    if not put(c, name, b"contenu"):
        rec("BROKEN", "self_upload", a)
    r = c.dav("GET", f"/files/{a}/{name}")
    if r.text != "contenu":
        rec("BROKEN", "self_download", a, {"status": r.status_code})
    r = c.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + name, "shareType": 3})
    tok = (data(r) or {}).get("token") if isinstance(data(r), dict) else None
    if not tok:
        rec("BROKEN", "self_public_link", a, {"status": r.status_code, "body": r.text[:200]})
    else:
        import requests
        pub = requests.get(f"{BASE}/public.php/dav/files/{tok}/", timeout=30)
        if pub.status_code >= 400 or "contenu" not in pub.text:
            rec("BROKEN", "self_public_link_open", a, {"status": pub.status_code})
    # partage par e-mail vers une adresse externe
    r = c.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + name, "shareType": 4, "shareWith": f"externe-{RUN}@example.org"})
    if r.status_code >= 400:
        rec("BROKEN", "self_share_external_email", a, {"status": r.status_code, "body": r.text[:200]})
    # agenda + carnet
    uid = f"self-{RUN}-{a}"
    ics = f"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//FR\r\nBEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:20261016T080000Z\r\nDTSTART:20261201T100000Z\r\nDTEND:20261201T110000Z\r\nSUMMARY:{uid}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    if c.dav("PUT", f"/calendars/{a}/personal/{uid}.ics", ics, ctype="text/calendar").status_code not in (201, 204):
        rec("BROKEN", "self_calendar_event", a)
    vcf = f"BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{uid}\r\nFN:Contact {RUN}\r\nEMAIL:contact-{RUN}@example.org\r\nEND:VCARD\r\n"
    if c.dav("PUT", f"/addressbooks/users/{a}/contacts/{uid}.vcf", vcf, ctype="text/vcard").status_code not in (201, 204):
        rec("BROKEN", "self_contact", a)
    # recherche de ses propres contacts (agenda, partage)
    r = c.req("POST", "/index.php/apps/calendar/v1/autocompletion/attendee", csrf=True, json={"search": f"Contact {RUN}"})
    if f"contact-{RUN}@example.org" not in r.text:
        rec("BROKEN", "self_attendee_search_own_contact", a, {"status": r.status_code, "body": r.text[:200]})
    # Talk
    if "Talk" in g or "admin" in g:
        r = c.ocs("POST", "/apps/spreed/api/v4/room", data={"roomType": 2, "roomName": f"self-{RUN}"})
        tok = (data(r) or {}).get("token") if isinstance(data(r), dict) else None
        if not tok:
            rec("BROKEN", "self_talk_room", a, {"status": r.status_code})
        else:
            m = c.ocs("POST", f"/apps/spreed/api/v1/chat/{tok}", data={"message": "bonjour " + RUN})
            if m.status_code >= 400:
                rec("BROKEN", "self_talk_message", a, {"status": m.status_code})
            # invité externe par e-mail
            e = c.ocs("POST", f"/apps/spreed/api/v4/room/{tok}/participants", data={"newParticipant": f"invite-{RUN}@example.org", "source": "emails"})
            if e.status_code >= 400:
                rec("BROKEN", "self_talk_email_guest", a, {"status": e.status_code, "body": e.text[:200]})
    # formulaire
    r = c.ocs("POST", "/apps/forms/api/v3/forms")
    if r.status_code >= 400:
        rec("BROKEN", "self_forms_create", a, {"status": r.status_code})
    # équipe
    r = c.ocs("POST", "/apps/circles/circles", json={"name": f"self-{RUN}-{a}"})
    if r.status_code >= 400:
        rec("BROKEN", "self_team_create", a, {"status": r.status_code})
    # notes
    r = c.req("POST", "/index.php/apps/notes/api/v1/notes", json={"title": f"note {RUN}", "content": "x"})
    if r.status_code >= 400:
        rec("BROKEN", "self_note", a, {"status": r.status_code, "body": r.text[:150]})
    # statut
    r = c.ocs("PUT", "/apps/user_status/api/v1/user_status/status", data={"statusType": "away"})
    if r.status_code >= 400:
        rec("BROKEN", "self_status", a, {"status": r.status_code})
    # recherche globale de ses fichiers
    r = c.ocs("GET", f"/search/providers/files/search?term=iso-{RUN}-{a}-self")
    if name not in r.text:
        rec("BROKEN", "self_unified_search_files", a, {"status": r.status_code})
    # profil et avatar de soi
    if c.ocs("GET", f"/profile/{a}").status_code >= 400:
        rec("BROKEN", "self_profile", a)
    if c.req("GET", f"/index.php/avatar/{a}/64").status_code >= 400:
        rec("BROKEN", "self_avatar", a)


def tid(u):
    """Désignation neutre d'une cible dans les noms de données de test."""
    return "t" + str(sorted(USERS).index(u))


def run():
    for a in ACTORS:
        self_tests(a)
        for t in [x for x in ACTORS if x != a]:
            ok = ZONE[t] == ZONE[a]
            v_libresign(a, t, ok)
            v_text_mention(a, t, ok)
            v_onlyoffice_mention(a, t, ok)
            v_forms_transfer(a, t, ok)
            v_proposal(a, t, ok)
            v_proxy(a, t, ok)
            v_talk_create_participants(a, t, ok)
            v_circle_multi(a, t, ok)
            v_public_links(t, a, ok)


if __name__ == "__main__":
    run()
    out = sys.argv[1] if len(sys.argv) > 1 else "out/actions2.json"
    os.makedirs(os.path.dirname(out) or ".", exist_ok=True)
    from lib import GHOST_SEEN
    for g in GHOST_SEEN:
        RESULTS.append({"kind": "GHOSTLEAK", "vector": g["method"] + " " + g["path"], "actor": g["actor"], "detail": g})
    json.dump(RESULTS, open(out, "w"), indent=1, ensure_ascii=False)
    from collections import Counter
    print("run", RUN, Counter(r["kind"] for r in RESULTS))
    agg = Counter((r["kind"], r["vector"], "famille" if ZONE[r["actor"]] == "famille" else "client") for r in RESULTS)
    for (k, v, z), n in sorted(agg.items()):
        print(f"{k:7} {z:8} {v:40} {n}")
