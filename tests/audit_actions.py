#!/usr/bin/env python3
"""Audit d'actions : un compte peut-il partager, discuter, inviter, mentionner,
transférer… vers un compte hors de sa cloison ? Chaque action est vérifiée des
deux côtés (réponse à l'acteur ET réception par la cible) ; la même action
vers un compte de la même cloison doit continuer de fonctionner.

  CROSS    l'action a atteint un compte d'une autre cloison
  ORACLE   la réponse diffère entre compte caché existant et compte inexistant
  BROKEN   l'action vers la même cloison (ou sur soi) ne fonctionne plus
"""
import json
import os
import sys
import time
import uuid

from lib import ACTORS, GHOST, HOST, USERS, ZONE, Client, hidden_for, norm, visible_for

RUN = uuid.uuid4().hex[:6]
RESULTS = []
C = {}


def rec(kind, vector, actor, detail):
    RESULTS.append({"kind": kind, "vector": vector, "actor": actor, "detail": detail})


def cl(u):
    if u not in C:
        C[u] = Client(u)
    return C[u]


def ocs_data(r):
    try:
        return r.json()["ocs"]["data"]
    except Exception:
        return None


# ------------------------------------------------------------------ helpers
def put_file(c, name, content=b"cloisons"):
    r = c.dav("PUT", f"/files/{c.uid}/{name}", content, ctype="text/plain")
    return r.status_code in (201, 204)


def shared_with_me(c):
    a = ocs_data(c.ocs("GET", "/apps/files_sharing/api/v1/shares?shared_with_me=true")) or []
    b = ocs_data(c.ocs("GET", "/apps/files_sharing/api/v1/shares/pending")) or []
    return json.dumps(a) + json.dumps(b)


def notifications(c):
    return c.ocs("GET", "/apps/notifications/api/v2/notifications").text


def rooms(c):
    return c.ocs("GET", "/apps/spreed/api/v4/room").text


def compare_oracle(vector, actor, fn, target):
    """fn(uid) -> response ; compare caché existant vs inexistant."""
    r1 = fn(target)
    r2 = fn(GHOST)
    if r1 is None or r2 is None:
        return
    if norm(r1, target, USERS[target][1] or "") != norm(r2, GHOST, "ghost@example.test"):
        rec("ORACLE", vector, actor, {"target": target, "s1": r1.status_code, "s2": r2.status_code,
                                      "b1": r1.text[:300], "b2": r2.text[:300]})


# ------------------------------------------------------------------ actions
def act_share_user(a, t, expect_ok):
    c = cl(a)
    name = f"iso-{RUN}-{a}-to-{tid(t)}.txt"
    put_file(c, name)
    r = c.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + name, "shareType": 0, "shareWith": t})
    arrived = name in shared_with_me(cl(t))
    judge("share_user", a, t, expect_ok, arrived, r)
    if not expect_ok:
        compare_oracle("share_user", a, lambda u: c.ocs("POST", "/apps/files_sharing/api/v1/shares",
                       data={"path": "/" + name, "shareType": 0, "shareWith": u}), t)


def act_share_federated(a, t, expect_ok):
    c = cl(a)
    name = f"iso-{RUN}-{a}-fed-{tid(t)}.txt"
    put_file(c, name)
    for host in (HOST, "localhost:" + HOST.rsplit(":", 1)[-1], "localhost"):
        r = c.ocs("POST", "/apps/files_sharing/api/v1/shares",
                  data={"path": "/" + name, "shareType": 6, "shareWith": f"{t}@{host}"})
        time.sleep(1)
        arrived = name in shared_with_me(cl(t)) or name in cl(t).ocs("GET", "/apps/files_sharing/api/v1/remote_shares/pending").text
        judge(f"share_federated[{host}]", a, t, expect_ok, arrived, r, ok_if_refused_same_zone=True)


def act_share_group(a, groups):
    """Partage à chaque groupe : ne doit atteindre aucun compte d'une autre cloison."""
    c = cl(a)
    for g in groups:
        name = f"iso-{RUN}-{a}-grp-{g.replace(' ', '_')}.txt"
        put_file(c, name)
        r = c.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + name, "shareType": 1, "shareWith": g})
        for t in hidden_for(a):
            if t == "bancadmin":
                continue
            if name in shared_with_me(cl(t)):
                rec("CROSS", f"share_group[{g}]", a, {"target": t, "status": r.status_code})


def act_share_email(a, t):
    """Partage par e-mail vers l'adresse d'un compte d'une autre cloison :
    autorisé comme sur une instance propre (lien public), mais ne doit pas
    devenir un partage interne ni révéler que l'adresse correspond à un compte."""
    c = cl(a)
    name = f"iso-{RUN}-{a}-mail-{tid(t)}.txt"
    put_file(c, name)
    r = c.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + name, "shareType": 4, "shareWith": USERS[t][1]})
    if name in shared_with_me(cl(t)):
        rec("CROSS", "share_email", a, {"target": t, "status": r.status_code})
    name2 = f"iso-{RUN}-{a}-mail-ghost-{tid(t)}.txt"  # un fichier par cible : sinon doublon refusé (403)
    put_file(c, name2)
    r2 = c.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": "/" + name2, "shareType": 4, "shareWith": "ghost@example.test"})
    if norm(r, USERS[t][1], name) != norm(r2, "ghost@example.test", name2):
        rec("ORACLE", "share_email", a, {"target": t, "s1": r.status_code, "s2": r2.status_code, "b1": r.text[:300], "b2": r2.text[:300]})


def act_transfer_ownership(a, t, expect_ok):
    c = cl(a)
    name = f"iso-{RUN}-{a}-xfer-{tid(t)}.txt"
    put_file(c, name)
    r = c.ocs("POST", "/apps/files/api/v1/transferownership", data={"recipient": t, "path": "/" + name})
    arrived = a in notifications(cl(t)) and "transfer" in notifications(cl(t)).lower()
    judge("transfer_ownership", a, t, expect_ok, arrived, r)
    if not expect_ok:
        compare_oracle("transfer_ownership", a, lambda u: c.ocs("POST", "/apps/files/api/v1/transferownership",
                       data={"recipient": u, "path": "/" + name}), t)


def act_talk_1to1(a, t, expect_ok):
    c = cl(a)
    r = c.ocs("POST", "/apps/spreed/api/v4/room", data={"roomType": 1, "invite": t})
    tok = (ocs_data(r) or {}).get("token") if isinstance(ocs_data(r), dict) else None
    arrived = bool(tok) and tok in rooms(cl(t))
    judge("talk_1to1", a, t, expect_ok, arrived, r)
    if not expect_ok:
        compare_oracle("talk_1to1", a, lambda u: c.ocs("POST", "/apps/spreed/api/v4/room", data={"roomType": 1, "invite": u}), t)


def talk_group_room(a):
    c = cl(a)
    r = c.ocs("POST", "/apps/spreed/api/v4/room", data={"roomType": 2, "roomName": f"iso-{RUN}-{a}"})
    d = ocs_data(r)
    return d.get("token") if isinstance(d, dict) else None


def act_talk_add(a, t, expect_ok, tok):
    c = cl(a)
    for source, val in (("users", t), ("emails", USERS[t][1])):
        if not val:
            continue
        r = c.ocs("POST", f"/apps/spreed/api/v4/room/{tok}/participants", data={"newParticipant": val, "source": source})
        arrived = tok in rooms(cl(t))
        c.ocs("GET", f"/apps/spreed/api/v4/room/{tok}/participants")  # détecteur de fantôme
        if source == "emails":
            # invitation par e-mail = invité externe ; ne doit jamais devenir un participant interne
            if arrived and not expect_ok:
                rec("CROSS", "talk_add[emails]", a, {"target": t, "status": r.status_code})
            continue
        judge(f"talk_add[{source}]", a, t, expect_ok, arrived, r)
        if not expect_ok:
            compare_oracle(f"talk_add[{source}]", a, lambda u: c.ocs("POST", f"/apps/spreed/api/v4/room/{tok}/participants",
                           data={"newParticipant": u, "source": source}), t)


def act_talk_add_groups(a, tok, groups):
    c = cl(a)
    for g in groups:
        r = c.ocs("POST", f"/apps/spreed/api/v4/room/{tok}/participants", data={"newParticipant": g, "source": "groups"})
        for t in hidden_for(a):
            if t != "bancadmin" and tok in rooms(cl(t)):
                rec("CROSS", f"talk_add_group[{g}]", a, {"target": t, "status": r.status_code})


def act_talk_listable(owner):
    """Salon « ouvert à tous les comptes » créé par `owner` : invisible et injoignable hors cloison."""
    c = cl(owner)
    r = c.ocs("POST", "/apps/spreed/api/v4/room", data={"roomType": 2, "roomName": f"iso-{RUN}-listable-{owner}", "listable": 1})
    d = ocs_data(r)
    tok = d.get("token") if isinstance(d, dict) else None
    if not tok:
        rec("BROKEN", "talk_listable_create", owner, {"status": r.status_code})
        return
    c.ocs("PUT", f"/apps/spreed/api/v4/room/{tok}/listable", data={"scope": 1})
    for t in ACTORS:
        if t == owner:
            continue
        lst = cl(t).ocs("GET", "/apps/spreed/api/v4/listed-room?searchTerm=iso-").text
        j = cl(t).ocs("POST", f"/apps/spreed/api/v4/room/{tok}/participants/active", data={})
        if ZONE[t] != ZONE[owner]:
            if tok in lst:
                rec("CROSS", "talk_listable_list", t, {"owner": owner})
            if j.status_code < 400:
                rec("CROSS", "talk_listable_join", t, {"owner": owner, "status": j.status_code})
        else:
            if tok not in lst:
                rec("BROKEN", "talk_listable_list", t, {"owner": owner})


def act_calendar_invite(a, t, expect_ok):
    c = cl(a)
    uid = f"iso-{RUN}-{a}-{tid(t)}"
    ics = f"""BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//cloisons//test//FR\r\nBEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:20261016T080000Z\r\nDTSTART:20261120T100000Z\r\nDTEND:20261120T110000Z\r\nSUMMARY:{uid}\r\nORGANIZER;CN={USERS[a][0]}:mailto:{USERS[a][1]}\r\nATTENDEE;CN=X;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT;RSVP=TRUE:mailto:{USERS[t][1]}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"""
    r = c.dav("PUT", f"/calendars/{a}/personal/{uid}.ics", ics, ctype="text/calendar; charset=utf-8")
    time.sleep(0.5)
    ct = cl(t)
    rep = ct.dav("REPORT", f"/calendars/{t}/personal/",
                 '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"/></c:filter></c:calendar-query>', depth=1)
    inbox = ct.dav("PROPFIND", f"/calendars/{t}/inbox/", None, depth=1)
    arrived = uid in rep.text or uid in inbox.text or uid in notifications(ct)
    judge("calendar_invite", a, t, expect_ok, arrived, r)
    # l'organisateur ne doit pas recevoir de statut de planification révélateur
    got = c.dav("REPORT", f"/calendars/{a}/personal/",
                '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"/></c:filter></c:calendar-query>', depth=1).text
    if not expect_ok and "SCHEDULE-STATUS=1.2" in got.split(uid)[-1][:2000]:
        rec("ORACLE", "calendar_invite_status", a, {"target": t, "detail": "SCHEDULE-STATUS=1.2 (livré en interne)"})


def act_calendar_freebusy(a, t):
    c = cl(a)
    body = f"""BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//cloisons//test//FR\r\nMETHOD:REQUEST\r\nBEGIN:VFREEBUSY\r\nUID:fb-{RUN}\r\nDTSTAMP:20261016T080000Z\r\nDTSTART:20261101T000000Z\r\nDTEND:20261201T000000Z\r\nORGANIZER:mailto:{USERS[a][1]}\r\nATTENDEE:mailto:{{m}}\r\nEND:VFREEBUSY\r\nEND:VCALENDAR\r\n"""
    fn = lambda m: c.dav("POST", f"/calendars/{a}/outbox/", body.replace("{m}", m), ctype="text/calendar; charset=utf-8")
    r1, r2 = fn(USERS[t][1]), fn("ghost@example.test")
    n1 = r1.text.replace(USERS[t][1], "<M>")
    n2 = r2.text.replace("ghost@example.test", "<M>")
    import re
    n1 = re.sub(r"DTSTAMP:\S+", "", n1); n2 = re.sub(r"DTSTAMP:\S+", "", n2)
    if (r1.status_code, n1) != (r2.status_code, n2):
        rec("ORACLE", "calendar_freebusy", a, {"target": t, "s1": r1.status_code, "b1": r1.text[:400], "b2": r2.text[:400]})


def act_dav_share(a, t, expect_ok, kind):
    c = cl(a)
    if kind == "calendar":
        path = f"/calendars/{a}/personal/"
        body = f"""<?xml version="1.0"?><o:share xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns"><o:set><d:href>principal:principals/users/{{u}}</d:href><o:read-only/></o:set></o:share>"""
    else:
        path = f"/addressbooks/users/{a}/contacts/"
        body = f"""<?xml version="1.0"?><o:share xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns"><o:set><d:href>principal:principals/users/{{u}}</d:href><o:read-only/></o:set></o:share>"""
    fn = lambda u: c.dav("POST", path, body.replace("{u}", u), ctype="application/xml; charset=utf-8")
    r = fn(t)
    home = f"/calendars/{t}/" if kind == "calendar" else f"/addressbooks/users/{t}/"
    seen = cl(t).dav("PROPFIND", home, '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>', depth=1).text
    arrived = f"_shared_by_{a}" in seen or f"personal_shared_by_{a}" in seen or f"contacts_shared_by_{a}" in seen
    judge(f"dav_share[{kind}]", a, t, expect_ok, arrived, r)
    if not expect_ok:
        compare_oracle(f"dav_share[{kind}]", a, fn, t)
    # partage vers des groupes
    for g in ("Office", "Talk", "admin", "Photos"):
        rg = c.dav("POST", path, body.replace("principal:principals/users/{u}", f"principal:principals/groups/{g}"),
                   ctype="application/xml; charset=utf-8")
        for h in hidden_for(a):
            if h == "bancadmin":
                continue
            hh = f"/calendars/{h}/" if kind == "calendar" else f"/addressbooks/users/{h}/"
            if f"_shared_by_{a}" in cl(h).dav("PROPFIND", hh, '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>', depth=1).text:
                rec("CROSS", f"dav_share_group[{kind}:{g}]", a, {"target": h, "status": rg.status_code})


def act_circle(a, t, expect_ok):
    c = cl(a)
    r = c.ocs("POST", "/apps/circles/circles", json={"name": f"iso-{RUN}-{a}-{tid(t)}"})
    d = ocs_data(r)
    cid = d.get("id") if isinstance(d, dict) else None
    if not cid:
        rec("BROKEN", "circle_create", a, {"status": r.status_code, "body": r.text[:200]})
        return
    r2 = c.ocs("POST", f"/apps/circles/circles/{cid}/members", json={"userId": t, "type": 1})
    arrived = cid in cl(t).ocs("GET", "/apps/circles/circles").text
    judge("circle_add_member", a, t, expect_ok, arrived, r2)
    if not expect_ok:
        compare_oracle("circle_add_member", a, lambda u: c.ocs("POST", f"/apps/circles/circles/{cid}/members", json={"userId": u, "type": 1}), t)
    for g in ("Office", "Talk"):
        r3 = c.ocs("POST", f"/apps/circles/circles/{cid}/members", json={"userId": g, "type": 2})
        for h in hidden_for(a):
            if h != "bancadmin" and cid in cl(h).ocs("GET", "/apps/circles/circles").text:
                rec("CROSS", f"circle_add_group[{g}]", a, {"target": h, "status": r3.status_code})


def act_comment_mention(a, t):
    c = cl(a)
    name = f"iso-{RUN}-{a}-cmt-{tid(t)}.txt"
    put_file(c, name)
    pf = c.dav("PROPFIND", f"/files/{a}/{name}", '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>', depth=0)
    import re
    m = re.search(r"<oc:fileid>(\d+)</oc:fileid>", pf.text)
    if not m:
        return
    fid = m.group(1)
    c.dav("POST", f"/comments/files/{fid}", json.dumps({"actorType": "users", "verb": "comment", "message": f"@{t} bonjour {RUN}", "objectType": "files"}), ctype="application/json")
    time.sleep(0.5)
    if name in notifications(cl(t)):
        rec("CROSS", "comment_mention", a, {"target": t})


def act_forms_share(a, t, expect_ok):
    c = cl(a)
    r = c.ocs("POST", "/apps/forms/api/v3/forms")
    d = ocs_data(r)
    fid = d.get("id") if isinstance(d, dict) else None
    if not fid:
        if ZONE[a] == "famille" or a in ("client-cccccccc",):
            rec("BROKEN", "forms_create", a, {"status": r.status_code})
        return
    c.ocs("PATCH", f"/apps/forms/api/v3/forms/{fid}", json={"keyValuePairs": {"title": f"iso-{RUN}-{a}"}})
    r2 = c.ocs("POST", f"/apps/forms/api/v3/forms/{fid}/shares", data={"shareType": 0, "shareWith": t, "permissions[]": "submit"})
    arrived = f"iso-{RUN}-{a}" in cl(t).ocs("GET", "/apps/forms/api/v3/forms?type=shared").text
    judge("forms_share_user", a, t, expect_ok, arrived, r2)
    if not expect_ok:
        compare_oracle("forms_share_user", a, lambda u: c.ocs("POST", f"/apps/forms/api/v3/forms/{fid}/shares",
                       data={"shareType": 0, "shareWith": u, "permissions[]": "submit"}), t)
    for g in ("Office", "Talk"):
        c.ocs("POST", f"/apps/forms/api/v3/forms/{fid}/shares", data={"shareType": 1, "shareWith": g, "permissions[]": "submit"})
        for h in hidden_for(a):
            if h != "bancadmin" and f"iso-{RUN}-{a}" in cl(h).ocs("GET", "/apps/forms/api/v3/forms?type=shared").text:
                rec("CROSS", f"forms_share_group[{g}]", a, {"target": h})


def act_ooo_replacement(a, t):
    c = cl(a)
    fn = lambda u: c.ocs("POST", f"/apps/dav/api/v1/outOfOffice/{a}", data={"firstDay": "2026-12-01", "lastDay": "2026-12-02",
                         "status": "Absent", "message": "x", "replacementUserId": u})
    compare_oracle("ooo_replacement", a, fn, t)
    c.ocs("DELETE", f"/apps/dav/api/v1/outOfOffice/{a}")


def judge(vector, a, t, expect_ok, arrived, r, ok_if_refused_same_zone=False):
    if expect_ok:
        if not arrived and not ok_if_refused_same_zone:
            rec("BROKEN", vector, a, {"target": t, "status": r.status_code, "body": r.text[:300]})
    else:
        if arrived:
            rec("CROSS", vector, a, {"target": t, "status": r.status_code})


def tid(u):
    """Désignation neutre d'une cible dans les noms de données de test."""
    return "t" + str(sorted(USERS).index(u))


def run():
    for a in ACTORS:
        targets = [t for t in ACTORS if t != a]
        tok = talk_group_room(a)
        for t in targets:
            ok = ZONE[t] == ZONE[a]
            act_share_user(a, t, ok)
            act_share_federated(a, t, ok)
            act_transfer_ownership(a, t, ok)
            act_talk_1to1(a, t, ok)
            if tok:
                act_talk_add(a, t, ok, tok)
            act_calendar_invite(a, t, ok)
            act_dav_share(a, t, ok, "calendar")
            act_dav_share(a, t, ok, "addressbook")
            act_circle(a, t, ok)
            act_forms_share(a, t, ok)
            if not ok:
                act_share_email(a, t)
                act_calendar_freebusy(a, t)
                act_comment_mention(a, t)
                act_ooo_replacement(a, t)
        act_share_group(a, ["Office", "Talk", "Photos", "IA", "Signatures", "Coffre", "Domotique", "Medias", "admin", "Intranet"])
        if tok:
            act_talk_add_groups(a, tok, ["Office", "Talk", "Photos", "admin"])
        else:
            rec("BROKEN", "talk_group_room_create", a, {})
    for owner in ("fam-anne", "client-aaaaaaaa"):
        act_talk_listable(owner)


if __name__ == "__main__":
    run()
    out = sys.argv[1] if len(sys.argv) > 1 else "out/actions.json"
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
