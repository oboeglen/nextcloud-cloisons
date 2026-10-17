#!/usr/bin/env python3
"""Fonctionnement : toutes les fonctions essentielles de Nextcloud doivent
marcher pour chaque compte dans sa cloison, et toutes les collaborations
au sein de la Famille doivent marcher comme sans l'application.

Chaque test produit PASS ou FAIL ; summarize.py compare à la mesure de
référence (sans l'application) : un test qui passait sans l'application et
échoue avec elle est une RÉGRESSION.
"""
import json
import os
import re
import sys
import time
import uuid

import requests

from lib import BASE, USERS, Client

RUN = uuid.uuid4().hex[:6]
RESULTS = []
C = {}
GROUPS = {
    "fam-anne": {"admin", "Office", "Photos", "Signatures", "Talk", "Coffre", "IA", "Assistant IA"},
    "fam-paul": {"Office", "Photos", "Talk", "IA"},
    "client-aaaaaaaa": {"Office", "Photos", "Talk", "Signatures", "Coffre", "IA"},
    "client-bbbbbbbb": {"Office", "Photos", "Talk", "Signatures", "Coffre", "IA"},
    "client-cccccccc": {"Office"},
    "client-dddddddd": set(),
}
ACTORS = list(GROUPS)


def cl(u):
    if u not in C:
        C[u] = Client(u)
    return C[u]


def check(name, actor, ok, detail=""):
    RESULTS.append({"kind": "PASS" if ok else "FAIL", "vector": name, "actor": actor, "detail": {"info": str(detail)[:300]}})
    return ok


def data(r):
    try:
        return r.json()["ocs"]["data"]
    except Exception:
        return None


def fileid(c, path):
    pf = c.dav("PROPFIND", f"/files/{c.uid}/{path}", '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>', depth=0)
    m = re.search(r"<oc:fileid>(\d+)</oc:fileid>", pf.text)
    return int(m.group(1)) if m else None


DAVX = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>'


# ================================================================ fichiers
def t_files(a):
    c = cl(a)
    base = f"fn-{RUN}"
    check("files_mkdir", a, c.dav("MKCOL", f"/files/{a}/{base}").status_code == 201)
    check("files_upload", a, c.dav("PUT", f"/files/{a}/{base}/doc.txt", b"bonjour", ctype="text/plain").status_code in (201, 204))
    check("files_download", a, c.dav("GET", f"/files/{a}/{base}/doc.txt").text == "bonjour")
    check("files_overwrite_version", a, c.dav("PUT", f"/files/{a}/{base}/doc.txt", b"bonjour v2", ctype="text/plain").status_code in (201, 204))
    fid = fileid(c, f"{base}/doc.txt")
    v = c.dav("PROPFIND", f"/versions/{a}/versions/{fid}", DAVX, depth=1)
    check("files_versions_list", a, v.status_code == 207 and v.text.count("<d:response>") >= 2, v.status_code)
    check("files_copy", a, c.dav("COPY", f"/files/{a}/{base}/doc.txt", extra={"Destination": f"{BASE}/remote.php/dav/files/{a}/{base}/copie.txt"}).status_code in (201, 204))
    check("files_move", a, c.dav("MOVE", f"/files/{a}/{base}/copie.txt", extra={"Destination": f"{BASE}/remote.php/dav/files/{a}/{base}/renomme.txt"}).status_code in (201, 204))
    fav = c.dav("PROPPATCH", f"/files/{a}/{base}/doc.txt", '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:set><d:prop><oc:favorite>1</oc:favorite></d:prop></d:set></d:propertyupdate>')
    check("files_favorite", a, fav.status_code == 207 and "200 OK" in fav.text)
    check("files_delete", a, c.dav("DELETE", f"/files/{a}/{base}/renomme.txt").status_code == 204)
    tb = c.dav("PROPFIND", f"/trashbin/{a}/trash", DAVX, depth=1)
    check("files_trash_list", a, tb.status_code == 207 and "renomme.txt" in tb.text)
    m = re.search(r"<d:href>([^<]*renomme\.txt[^<]*)</d:href>", tb.text)
    if m:
        r = requests.request("MOVE", BASE + m.group(1), auth=(a, cl(a).s.auth[1]),
                             headers={"Destination": f"{BASE}/remote.php/dav/trashbin/{a}/restore/renomme.txt"})
        check("files_trash_restore", a, r.status_code in (201, 204), r.status_code)
    # commentaire sur son fichier
    r = c.dav("POST", f"/comments/files/{fid}", json.dumps({"actorType": "users", "verb": "comment", "message": "note perso", "objectType": "files"}), ctype="application/json")
    check("files_comment", a, r.status_code == 201, r.status_code)
    # aperçu, récents, statistiques, arborescence
    import base64
    c.dav("PUT", f"/files/{a}/{base}/img.png", base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="), ctype="image/png")
    pid = fileid(c, f"{base}/img.png")
    check("files_preview", a, c.req("GET", f"/index.php/core/preview?fileId={pid}&x=32&y=32").status_code == 200)
    check("files_recent", a, c.req("GET", "/index.php/apps/files/api/v1/recent/", csrf=True).status_code == 200)
    check("files_folder_tree", a, c.ocs("GET", "/apps/files/api/v1/folder-tree?path=/").status_code == 200)
    s = c.dav("SEARCH", "/", f'''<?xml version="1.0"?><d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:basicsearch><d:select><d:prop><d:displayname/></d:prop></d:select><d:from><d:scope><d:href>/files/{a}</d:href><d:depth>infinity</d:depth></d:scope></d:from><d:where><d:like><d:prop><d:displayname/></d:prop><d:literal>doc%</d:literal></d:like></d:where></d:basicsearch></d:searchrequest>''', ctype="text/xml")
    check("files_dav_search", a, s.status_code == 207 and "doc.txt" in s.text, s.status_code)
    us = c.ocs("GET", f"/search/providers/files/search?term=doc")
    check("files_unified_search", a, "doc.txt" in us.text)
    return base


# =========================================================== partage externe
def t_sharing(a, base):
    c = cl(a)
    r = c.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": f"/{base}/doc.txt", "shareType": 3})
    d = data(r)
    tok = d.get("token") if isinstance(d, dict) else None
    check("share_public_link", a, bool(tok), r.status_code)
    if tok:
        check("share_public_link_open", a, requests.get(f"{BASE}/index.php/s/{tok}", timeout=30).status_code == 200)
        check("share_public_link_dav", a, requests.get(f"{BASE}/public.php/dav/files/{tok}", timeout=30).text == "bonjour v2")
        sid = d.get("id")
        u = c.ocs("PUT", f"/apps/files_sharing/api/v1/shares/{sid}", data={"password": f"Mdp-{RUN}-tres-long!", "expireDate": "2027-01-01"})
        check("share_public_link_update", a, u.status_code == 200, u.text[:200])
        check("share_public_link_delete", a, c.ocs("DELETE", f"/apps/files_sharing/api/v1/shares/{sid}").status_code == 200)
    # dépôt de fichiers (lien en écriture) ouvert par un anonyme
    c.dav("MKCOL", f"/files/{a}/{base}/depot")
    r = c.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": f"/{base}/depot", "shareType": 3, "permissions": 4})
    d = data(r)
    tok = d.get("token") if isinstance(d, dict) else None
    if tok:
        up = requests.put(f"{BASE}/public.php/dav/files/{tok}/depose.txt", data=b"x", timeout=30)
        check("share_file_drop_anonymous_upload", a, up.status_code in (201, 204), up.status_code)
    r = c.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": f"/{base}/doc.txt", "shareType": 4, "shareWith": f"ext-{RUN}@example.org"})
    check("share_email_external", a, r.status_code == 200, r.text[:200])
    se = c.ocs("GET", f"/apps/files_sharing/api/v1/sharees?search=ext2-{RUN}@example.org&itemType=file")
    check("sharees_external_email_suggestion", a, f"ext2-{RUN}@example.org" in se.text)
    check("shares_list_own", a, f"ext-{RUN}@example.org" in c.ocs("GET", "/apps/files_sharing/api/v1/shares").text)


# ======================================================== agenda / contacts
def t_calendar(a):
    c = cl(a)
    cal = f"cal-{RUN}"
    mk = c.dav("MKCALENDAR", f"/calendars/{a}/{cal}/", f'<?xml version="1.0"?><c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:set><d:prop><d:displayname>Agenda {RUN}</d:displayname></d:prop></d:set></c:mkcalendar>')
    check("calendar_create", a, mk.status_code == 201, mk.status_code)
    ev = f"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//FR\r\nBEGIN:VEVENT\r\nUID:ev-{RUN}\r\nDTSTAMP:20261016T080000Z\r\nDTSTART:20261210T100000Z\r\nDTEND:20261210T110000Z\r\nSUMMARY:Réunion {RUN}\r\nATTENDEE;CN=Externe;RSVP=TRUE:mailto:invite-{RUN}@example.org\r\nORGANIZER:mailto:{USERS[a][1]}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    check("calendar_event_create_with_external_attendee", a, c.dav("PUT", f"/calendars/{a}/{cal}/ev.ics", ev, ctype="text/calendar").status_code == 201)
    check("calendar_event_read", a, f"Réunion {RUN}" in c.dav("GET", f"/calendars/{a}/{cal}/ev.ics").text)
    check("calendar_event_update", a, c.dav("PUT", f"/calendars/{a}/{cal}/ev.ics", ev.replace("Réunion", "Point"), ctype="text/calendar").status_code == 204)
    todo = f"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//FR\r\nBEGIN:VTODO\r\nUID:todo-{RUN}\r\nDTSTAMP:20261016T080000Z\r\nSUMMARY:Tâche {RUN}\r\nEND:VTODO\r\nEND:VCALENDAR\r\n"
    check("tasks_create", a, c.dav("PUT", f"/calendars/{a}/{cal}/todo.ics", todo, ctype="text/calendar").status_code == 201)
    us = c.ocs("GET", f"/search/providers/calendar/search?term=Point")
    check("calendar_unified_search", a, f"Point {RUN}" in us.text)
    q = c.dav("REPORT", f"/calendars/{a}/{cal}/", '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/></c:comp-filter></c:filter></c:calendar-query>', depth=1)
    check("calendar_query", a, q.status_code == 207 and "ev.ics" in q.text)
    pub = c.dav("POST", f"/calendars/{a}/{cal}/", '<?xml version="1.0"?><cs:publish-calendar xmlns:cs="http://calendarserver.org/ns/"/>')
    check("calendar_publish_link", a, pub.status_code in (200, 202, 204), pub.status_code)
    check("calendar_event_delete", a, c.dav("DELETE", f"/calendars/{a}/{cal}/ev.ics").status_code == 204)
    # rendez-vous (page de réservation)
    ap = c.req("POST", "/index.php/apps/calendar/v1/appointment_configs", csrf=True, json={
        "name": f"RDV {RUN}", "description": "", "location": "", "visibility": "PUBLIC", "targetCalendarUri": cal,
        "availability": {"timezoneId": "Europe/Paris", "slots": {"MO": [{"start": 1792130400, "end": 1792159200}], "TU": [], "WE": [], "TH": [], "FR": [], "SA": [], "SU": []}},
        "length": 1800, "increment": 1800, "preparationDuration": 0, "followupDuration": 0, "timeBeforeNextSlot": 0, "calendarFreeBusyUris": []})
    check("calendar_appointment_create", a, ap.status_code in (200, 201), ap.text[:200])


def t_contacts(a):
    c = cl(a)
    ab = f"ab-{RUN}"
    mk = c.dav("MKCOL", f"/addressbooks/users/{a}/{ab}/", '<?xml version="1.0"?><d:mkcol xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:set><d:prop><d:resourcetype><d:collection/><card:addressbook/></d:resourcetype><d:displayname>Carnet</d:displayname></d:prop></d:set></d:mkcol>')
    check("contacts_addressbook_create", a, mk.status_code == 201, mk.status_code)
    vcf = f"BEGIN:VCARD\r\nVERSION:3.0\r\nUID:ct-{RUN}\r\nFN:Paul Contact{RUN}\r\nEMAIL:paul-{RUN}@example.org\r\nEND:VCARD\r\n"
    check("contacts_card_create", a, c.dav("PUT", f"/addressbooks/users/{a}/{ab}/ct.vcf", vcf, ctype="text/vcard").status_code == 201)
    check("contacts_card_update", a, c.dav("PUT", f"/addressbooks/users/{a}/{ab}/ct.vcf", vcf.replace("Paul", "Pierre"), ctype="text/vcard").status_code == 204)
    m = c.req("POST", "/index.php/contactsmenu/contacts", csrf=True, data={"filter": f"Contact{RUN}"})
    check("contacts_menu_own_contact", a, f"paul-{RUN}@example.org" in m.text, m.status_code)
    at = c.req("POST", "/index.php/apps/calendar/v1/autocompletion/attendee", csrf=True, json={"search": f"Contact{RUN}"})
    check("calendar_attendee_own_contact", a, f"paul-{RUN}@example.org" in at.text)
    sh = c.ocs("GET", f"/apps/files_sharing/api/v1/sharees?search=Contact{RUN}&itemType=file")
    check("sharees_own_contact", a, f"paul-{RUN}@example.org" in sh.text)
    check("contacts_card_delete", a, c.dav("DELETE", f"/addressbooks/users/{a}/{ab}/ct.vcf").status_code == 204)
    # contact personnel portant l'adresse d'un compte d'une autre cloison : reste le sien, partout
    other = "client-bbbbbbbb" if a != "client-bbbbbbbb" else "client-aaaaaaaa"
    if a.startswith("fam-"):
        other = "client-cccccccc"
    mail = USERS[other][1]
    vcf2 = f"BEGIN:VCARD\r\nVERSION:3.0\r\nUID:perso-{RUN}\r\nFN:Perso{RUN} Connu\r\nEMAIL:{mail}\r\nEND:VCARD\r\n"
    c.dav("PUT", f"/addressbooks/users/{a}/{ab}/perso.vcf", vcf2, ctype="text/vcard")
    m = c.req("POST", "/index.php/contactsmenu/contacts", csrf=True, data={"filter": f"Perso{RUN}"})
    check("contacts_menu_personal_contact_with_other_zone_email", a, f"Perso{RUN}" in m.text and USERS[other][0] not in m.text)
    at = c.req("POST", "/index.php/apps/calendar/v1/autocompletion/attendee", csrf=True, json={"search": f"Perso{RUN}"})
    check("calendar_attendee_personal_contact_with_other_zone_email", a, mail in at.text and USERS[other][0] not in at.text)
    sh = c.ocs("GET", f"/apps/files_sharing/api/v1/sharees?search=Perso{RUN}&itemType=file")
    check("sharees_personal_contact_with_other_zone_email", a, mail in sh.text and USERS[other][0] not in sh.text)


# ===================================================================== Talk
def t_talk(a):
    if "Talk" not in GROUPS[a] and "admin" not in GROUPS[a]:
        # sans le droit Talk : la création reste refusée (comportement natif)
        r = cl(a).ocs("POST", "/apps/spreed/api/v4/room", data={"roomType": 2, "roomName": "x"})
        check("talk_no_entitlement_denied", a, r.status_code >= 400)
        check("talk_list_rooms", a, cl(a).ocs("GET", "/apps/spreed/api/v4/room").status_code == 200)
        return
    c = cl(a)
    r = c.ocs("POST", "/apps/spreed/api/v4/room", data={"roomType": 2, "roomName": f"Salon {RUN}"})
    d = data(r)
    tok = d.get("token") if isinstance(d, dict) else None
    if not check("talk_create_group_room", a, bool(tok), r.status_code):
        return
    check("talk_rename", a, c.ocs("PUT", f"/apps/spreed/api/v4/room/{tok}", data={"roomName": f"Salon renommé {RUN}"}).status_code == 200)
    m = c.ocs("POST", f"/apps/spreed/api/v1/chat/{tok}", data={"message": f"Bonjour {RUN}"})
    check("talk_send_message", a, m.status_code == 201, m.status_code)
    mid = (data(m) or {}).get("id") if isinstance(data(m), dict) else None
    if mid:
        check("talk_edit_message", a, c.ocs("PUT", f"/apps/spreed/api/v1/chat/{tok}/{mid}", data={"message": f"Bonjour modifié {RUN}"}).status_code in (200, 202))
        check("talk_react", a, c.ocs("POST", f"/apps/spreed/api/v1/reaction/{tok}/{mid}", data={"reaction": "👍"}).status_code in (200, 201))
    check("talk_read_messages", a, f"{RUN}" in c.ocs("GET", f"/apps/spreed/api/v1/chat/{tok}?lookIntoFuture=0&limit=20").text)
    p = c.ocs("POST", f"/apps/spreed/api/v1/poll/{tok}", json={"question": "Q?", "options": ["a", "b"], "resultMode": 0, "maxVotes": 1})
    check("talk_poll", a, p.status_code == 201, p.status_code)
    e = c.ocs("POST", f"/apps/spreed/api/v4/room/{tok}/participants", data={"newParticipant": f"guest-{RUN}@example.org", "source": "emails"})
    check("talk_invite_external_email", a, e.status_code == 200, e.text[:200])
    pr = c.ocs("POST", "/apps/spreed/api/v4/room", data={"roomType": 3, "roomName": f"Public {RUN}"})
    ptok = (data(pr) or {}).get("token") if isinstance(data(pr), dict) else None
    check("talk_create_public_room", a, bool(ptok), pr.status_code)
    if ptok:
        g = requests.post(f"{BASE}/ocs/v2.php/apps/spreed/api/v4/room/{ptok}/participants/active?format=json", headers={"OCS-APIRequest": "true"}, timeout=30)
        check("talk_public_room_anonymous_guest_join", a, g.status_code == 200, g.status_code)
    ns = c.ocs("GET", "/apps/spreed/api/v4/room/note-to-self")
    check("talk_note_to_self", a, ns.status_code == 200, ns.status_code)
    us = c.ocs("GET", f"/search/providers/talk-message/search?term={RUN}")
    check("talk_message_search", a, us.status_code == 200)
    j = c.ocs("POST", f"/apps/spreed/api/v4/room/{tok}/participants/active", data={})
    check("talk_join_own_room", a, j.status_code == 200, j.status_code)
    call = c.ocs("POST", f"/apps/spreed/api/v4/call/{tok}", data={"flags": 1})
    check("talk_start_call", a, call.status_code == 200, call.status_code)


# ======================================================== autres applications
def t_apps(a):
    c = cl(a)
    # formulaires : créer, question, lien public, répondre
    r = c.ocs("POST", "/apps/forms/api/v3/forms")
    fid = (data(r) or {}).get("id") if isinstance(data(r), dict) else None
    if check("forms_create", a, bool(fid), r.status_code):
        c.ocs("PATCH", f"/apps/forms/api/v3/forms/{fid}", json={"keyValuePairs": {"title": f"Sondage {RUN}"}})
        q = c.ocs("POST", f"/apps/forms/api/v3/forms/{fid}/questions", json={"type": "short", "text": "Nom ?"})
        qid = (data(q) or {}).get("id") if isinstance(data(q), dict) else None
        check("forms_add_question", a, bool(qid), q.status_code)
        sh = c.ocs("POST", f"/apps/forms/api/v3/forms/{fid}/shares", data={"shareType": 3})
        check("forms_public_link", a, sh.status_code in (200, 201), sh.text[:150])
        if qid:
            s = c.ocs("POST", f"/apps/forms/api/v3/forms/{fid}/submissions", json={"answers": {str(qid): ["reponse-ok"]}})
            check("forms_submit_own", a, s.status_code in (200, 201), s.text[:150])
            check("forms_results", a, "reponse-ok" in c.ocs("GET", f"/apps/forms/api/v3/forms/{fid}/submissions").text)
    # notes
    n = c.req("POST", "/index.php/apps/notes/api/v1/notes", json={"title": f"Note {RUN}", "content": "contenu"})
    nid = n.json().get("id") if n.status_code < 400 else None
    check("notes_create", a, bool(nid), n.status_code)
    if nid:
        check("notes_update", a, c.req("PUT", f"/index.php/apps/notes/api/v1/notes/{nid}", json={"content": "modifié"}).status_code == 200)
        check("notes_list", a, f"Note {RUN}" in c.req("GET", "/index.php/apps/notes/api/v1/notes").text)
    # équipes
    t = c.ocs("POST", "/apps/circles/circles", json={"name": f"Équipe {RUN} {a}"})
    tid = (data(t) or {}).get("id") if isinstance(data(t), dict) else None
    check("teams_create", a, bool(tid), t.status_code)
    if tid:
        check("teams_list_own", a, tid in c.ocs("GET", "/apps/circles/circles").text)
        check("teams_add_external_email", a, c.ocs("POST", f"/apps/circles/circles/{tid}/members", json={"userId": f"ami-{RUN}@example.org", "type": 4}).status_code == 200)
    # collectifs
    co = c.ocs("POST", "/apps/collectives/api/v1.0/collectives", json={"name": f"Wiki {RUN} {a[-4:]}"})
    check("collectives_create", a, co.status_code == 200, co.text[:150])
    # statut, profil, réglages, avatar, mots de passe d'application
    check("status_set", a, c.ocs("PUT", "/apps/user_status/api/v1/user_status/status", data={"statusType": "dnd"}).status_code == 200)
    check("status_message", a, c.ocs("PUT", "/apps/user_status/api/v1/user_status/message/custom", data={"message": "En vacances"}).status_code == 200)
    check("status_get_own", a, "En vacances" in c.ocs("GET", "/apps/user_status/api/v1/user_status").text)
    check("profile_own", a, c.ocs("GET", f"/profile/{a}").status_code == 200)
    check("settings_own_details", a, c.ocs("GET", f"/cloud/users/{a}").status_code == 200)
    check("settings_editable_fields", a, c.ocs("GET", "/cloud/user/fields").status_code == 200)
    check("settings_user_preference", a, c.ocs("POST", "/apps/provisioning_api/api/v1/config/users/files/show_hidden", data={"configValue": "0"}).status_code in (200, 400))
    check("avatar_own", a, c.req("GET", f"/index.php/avatar/{a}/64").status_code == 200)
    check("notifications_list", a, c.ocs("GET", "/apps/notifications/api/v2/notifications").status_code == 200)
    check("activity_list", a, c.ocs("GET", "/apps/activity/api/v2/activity").status_code in (200, 304))
    check("dashboard_widgets", a, c.ocs("GET", "/apps/dashboard/api/v1/widgets").status_code == 200)
    check("unified_search_providers", a, c.ocs("GET", "/search/providers").status_code == 200)
    check("own_groups", a, c.ocs("GET", f"/cloud/users/{a}/groups").status_code == 200)
    # Text : session sur son document
    c.dav("PUT", f"/files/{a}/note-{RUN}.md", b"# Titre", ctype="text/markdown")
    fid2 = fileid(c, f"note-{RUN}.md")
    s = c.req("PUT", f"/index.php/apps/text/session/{fid2}/create", csrf=True, json={"fileId": fid2})
    check("text_open_document", a, s.status_code == 200, s.status_code)
    # applications liées aux droits
    if "Signatures" in GROUPS[a]:
        check("libresign_account", a, c.ocs("GET", "/apps/libresign/api/v1/account/me").status_code == 200)
    if "Photos" in GROUPS[a]:
        check("immich_integration_settings", a, c.req("GET", "/index.php/apps/integration_immich/").status_code in (200, 303))
    if "Office" in GROUPS[a]:
        check("whiteboard_available", a, c.req("GET", "/index.php/apps/whiteboard/settings").status_code < 500)


# ============================================== collaboration au sein de la Famille
def t_family(a, b):
    ca, cb = cl(a), cl(b)
    base = f"fam-{RUN}-{a[-4:]}"
    ca.dav("MKCOL", f"/files/{a}/{base}")
    ca.dav("PUT", f"/files/{a}/{base}/partage.txt", b"famille", ctype="text/plain")
    se = ca.ocs("GET", f"/apps/files_sharing/api/v1/sharees?search={USERS[b][0].split()[1]}&itemType=folder")
    check("fam_sharees_finds_partner", a, b in se.text)
    r = ca.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": f"/{base}", "shareType": 0, "shareWith": b, "permissions": 31})
    check("fam_share_folder_to_partner", a, r.status_code == 200, r.text[:200])
    time.sleep(0.5)
    check("fam_partner_sees_share", a, base in cb.ocs("GET", "/apps/files_sharing/api/v1/shares?shared_with_me=true").text)
    up = cb.dav("PUT", f"/files/{b}/{base}/reponse.txt", b"ok", ctype="text/plain")
    check("fam_partner_writes_in_share", a, up.status_code in (201, 204), up.status_code)
    # Talk
    if ({"Talk", "admin"} & GROUPS[a]) and ({"Talk", "admin"} & GROUPS[b]):
        r = ca.ocs("POST", "/apps/spreed/api/v4/room", data={"roomType": 1, "invite": b})
        tok = (data(r) or {}).get("token") if isinstance(data(r), dict) else None
        check("fam_talk_1to1", a, bool(tok), r.status_code)
        if tok:
            ca.ocs("POST", f"/apps/spreed/api/v1/chat/{tok}", data={"message": f"Salut @{b} {RUN}"})
            check("fam_talk_partner_reads", a, RUN in cb.ocs("GET", f"/apps/spreed/api/v1/chat/{tok}?lookIntoFuture=0&limit=10").text)
        g = ca.ocs("POST", "/apps/spreed/api/v4/room", data={"roomType": 2, "roomName": f"Groupe {RUN}"})
        gt = (data(g) or {}).get("token") if isinstance(data(g), dict) else None
        if gt:
            ad = ca.ocs("POST", f"/apps/spreed/api/v4/room/{gt}/participants", data={"newParticipant": b, "source": "users"})
            check("fam_talk_add_partner", a, ad.status_code == 200 and gt in cb.ocs("GET", "/apps/spreed/api/v4/room").text, ad.status_code)
            ln = ca.ocs("PUT", f"/apps/spreed/api/v4/room/{gt}/listable", data={"scope": 1})
            check("fam_talk_open_room_visible_to_partner", a, ln.status_code == 200)
    # agenda : partage, invitation interne, disponibilités
    sh = ca.dav("POST", f"/calendars/{a}/personal/", f'<?xml version="1.0"?><o:share xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns"><o:set><d:href>principal:principals/users/{b}</d:href><o:read-write/></o:set></o:share>')
    check("fam_calendar_share", a, sh.status_code in (200, 204), sh.status_code)
    check("fam_partner_sees_calendar", a, f"_shared_by_{a}" in cb.dav("PROPFIND", f"/calendars/{b}/", DAVX, depth=1).text)
    uid = f"inv-{RUN}-{a[-4:]}"
    ev = f"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//FR\r\nBEGIN:VEVENT\r\nUID:{uid}\r\nDTSTAMP:20261016T080000Z\r\nDTSTART:20261212T100000Z\r\nDTEND:20261212T110000Z\r\nSUMMARY:{uid}\r\nORGANIZER;CN=A:mailto:{USERS[a][1]}\r\nATTENDEE;CN=B;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:{USERS[b][1]}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
    ca.dav("PUT", f"/calendars/{a}/personal/{uid}.ics", ev, ctype="text/calendar")
    time.sleep(0.5)
    rep = cb.dav("REPORT", f"/calendars/{b}/personal/", '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"/></c:filter></c:calendar-query>', depth=1)
    check("fam_calendar_invitation_delivered", a, uid in rep.text)
    at = ca.req("POST", "/index.php/apps/calendar/v1/autocompletion/attendee", csrf=True, json={"search": USERS[b][0].split()[1]})
    check("fam_attendee_search_finds_partner", a, USERS[b][1] in at.text)
    ps = ca.dav("REPORT", "/", f'<?xml version="1.0"?><d:principal-property-search xmlns:d="DAV:" test="anyof"><d:property-search><d:prop><d:displayname/></d:prop><d:match>{USERS[b][0].split()[1]}</d:match></d:property-search><d:prop><d:displayname/></d:prop><d:apply-to-principal-collection-set/></d:principal-property-search>', depth=0)
    check("fam_principal_search_finds_partner", a, f"principals/users/{b}" in ps.text)
    # carnet partagé
    sh = ca.dav("POST", f"/addressbooks/users/{a}/contacts/", f'<?xml version="1.0"?><o:share xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns"><o:set><d:href>principal:principals/users/{b}</d:href><o:read-only/></o:set></o:share>')
    check("fam_addressbook_share", a, sh.status_code in (200, 204) and f"_shared_by_{a}" in cb.dav("PROPFIND", f"/addressbooks/users/{b}/", DAVX, depth=1).text)
    # équipes
    t = ca.ocs("POST", "/apps/circles/circles", json={"name": f"Famille {RUN} {a[-4:]}"})
    tid = (data(t) or {}).get("id") if isinstance(data(t), dict) else None
    if tid:
        ad = ca.ocs("POST", f"/apps/circles/circles/{tid}/members", json={"userId": b, "type": 1})
        check("fam_team_add_partner", a, ad.status_code == 200 and tid in cb.ocs("GET", "/apps/circles/circles").text, ad.status_code)
    # formulaires partagés
    r = ca.ocs("POST", "/apps/forms/api/v3/forms")
    fid = (data(r) or {}).get("id") if isinstance(data(r), dict) else None
    if fid:
        ca.ocs("PATCH", f"/apps/forms/api/v3/forms/{fid}", json={"keyValuePairs": {"title": f"FamForm {RUN}"}})
        s = ca.ocs("POST", f"/apps/forms/api/v3/forms/{fid}/shares", data={"shareType": 0, "shareWith": b, "permissions[]": "submit"})
        check("fam_forms_share_partner", a, s.status_code in (200, 201) and f"FamForm {RUN}" in cb.ocs("GET", "/apps/forms/api/v3/forms?type=shared").text, s.status_code)
    # visibilité mutuelle
    check("fam_profile_partner", a, ca.ocs("GET", f"/profile/{b}").status_code == 200)
    check("fam_avatar_partner", a, ca.req("GET", f"/index.php/avatar/{b}/64").status_code == 200)
    check("fam_contactsmenu_partner", a, b in ca.req("POST", "/index.php/contactsmenu/contacts", csrf=True, data={"filter": USERS[b][0].split()[1]}).text)
    check("fam_displaynames_partner", a, USERS[b][0] in ca.req("POST", "/index.php/displaynames", csrf=True, json={"users": [b]}).text)
    check("fam_autocomplete_partner", a, b in ca.ocs("GET", f"/core/autocomplete/get?search={USERS[b][0].split()[1]}&itemType=files&itemId=1&shareTypes[]=0").text)
    # mention en commentaire sur un fichier partagé → notification
    fid3 = fileid(ca, f"{base}/partage.txt")
    ca.dav("POST", f"/comments/files/{fid3}", json.dumps({"actorType": "users", "verb": "comment", "message": f"@{b} regarde {RUN}", "objectType": "files"}), ctype="application/json")
    time.sleep(0.5)
    check("fam_comment_mention_notified", a, '"app":"comments"' in cb.ocs("GET", "/apps/notifications/api/v2/notifications").text)
    # groupe de la cloison (« Famille ») : cible de partage, de conversation et d'agenda
    ca.dav("PUT", f"/files/{a}/grp-{RUN}.txt", b"g", ctype="text/plain")
    g = ca.ocs("POST", "/apps/files_sharing/api/v1/shares", data={"path": f"/grp-{RUN}.txt", "shareType": 1, "shareWith": "cloison-famille"})
    time.sleep(0.3)
    check("fam_share_to_family_group", a, g.status_code == 200 and f"grp-{RUN}.txt" in cb.ocs("GET", "/apps/files_sharing/api/v1/shares?shared_with_me=true").text, g.text[:150])
    sg = ca.ocs("GET", "/apps/files_sharing/api/v1/sharees?search=Famille&itemType=file")
    check("fam_sharees_finds_family_group", a, "cloison-famille" in sg.text)
    if ({"Talk", "admin"} & GROUPS[a]):
        rg = ca.ocs("POST", "/apps/spreed/api/v4/room", data={"roomType": 2, "roomName": f"FamGrp {RUN}"})
        gt2 = (data(rg) or {}).get("token") if isinstance(data(rg), dict) else None
        if gt2:
            ad = ca.ocs("POST", f"/apps/spreed/api/v4/room/{gt2}/participants", data={"newParticipant": "cloison-famille", "source": "groups"})
            check("fam_talk_add_family_group", a, ad.status_code == 200 and gt2 in cb.ocs("GET", "/apps/spreed/api/v4/room").text, ad.status_code)
    cs = ca.dav("POST", f"/calendars/{a}/personal/", '<?xml version="1.0"?><o:share xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns"><o:set><d:href>principal:principals/groups/cloison-famille</d:href><o:read-only/></o:set></o:share>')
    check("fam_calendar_share_family_group", a, cs.status_code in (200, 204), cs.status_code)
    # transfert de propriété
    ca.dav("PUT", f"/files/{a}/xfer-{RUN}.txt", b"x", ctype="text/plain")
    tr = ca.ocs("POST", "/apps/files/api/v1/transferownership", data={"recipient": b, "path": f"/xfer-{RUN}.txt"})
    check("fam_transfer_ownership_request", a, tr.status_code == 200, tr.text[:150])


def run():
    for a in ACTORS:
        base = t_files(a)
        t_sharing(a, base)
        t_calendar(a)
        t_contacts(a)
        t_talk(a)
        t_apps(a)
    t_family("fam-anne", "fam-paul")
    t_family("fam-paul", "fam-anne")


if __name__ == "__main__":
    run()
    out = sys.argv[1] if len(sys.argv) > 1 else "out/functions.json"
    os.makedirs(os.path.dirname(out) or ".", exist_ok=True)
    from lib import GHOST_SEEN
    for g in GHOST_SEEN:
        RESULTS.append({"kind": "GHOSTLEAK", "vector": g["method"] + " " + g["path"], "actor": g["actor"], "detail": g})
    json.dump(RESULTS, open(out, "w"), indent=1, ensure_ascii=False)
    from collections import Counter
    print("run", RUN, Counter(r["kind"] for r in RESULTS))
    for r in RESULTS:
        if r["kind"] != "PASS":
            print(f"{r['kind']:5} {r['actor']:16} {r['vector']:45} {r['detail'].get('info', '')[:120]}")
