"""Outils communs du banc de cloisonnement (comptes fictifs uniquement)."""
import json
import re
import os
import requests

_HERE = os.path.dirname(os.path.abspath(__file__))
# Adresse du banc et dossier de configuration (mots de passe générés, hors dépôt)
BASE = os.environ.get("CLOISONS_BASE", "http://127.0.0.1:18980")
CONF = os.environ.get("CLOISONS_CONF", os.path.join(_HERE, "..", "bench", "conf"))
BENCH = os.environ.get("CLOISONS_BENCH", os.path.join(_HERE, "..", "bench"))
APPPW = json.load(open(CONF + "/apppw.json"))
# hôte:port du banc, tel qu'il apparaît dans les identifiants fédérés (compte@hôte)
HOST = BASE.split("://", 1)[-1].rstrip("/")

USERS = {
    "fam-anne": ("Famille Anne", "fam-anne@example.test"),
    "fam-paul": ("Famille Paul", "fam-paul@example.test"),
    "client-aaaaaaaa": ("Alice Client", "alice@example.test"),
    "client-bbbbbbbb": ("Bruno Client", "bruno@example.test"),
    "client-cccccccc": ("Chloé Client", "chloe@example.test"),
    "client-dddddddd": ("David Client", "david@example.test"),
    "bancadmin": ("bancadmin", None),
}
ZONE = {
    "fam-anne": "famille", "fam-paul": "famille",
    "client-aaaaaaaa": "a", "client-bbbbbbbb": "b",
    "client-cccccccc": "c", "client-dddddddd": "d",
    "bancadmin": "admin",
}
ACTORS = [u for u in USERS if u != "bancadmin"]
ADMINS = {"fam-anne"}
# Vecteurs de gestion des comptes : l'administrateur voit tout (voulu)
ADMIN_VECTORS = ("cloud_user", "cloud_users", "group", "dav_group_principal")
import uuid as _uuid
# compte inexistant, différent à chaque campagne (évite les caches de Nextcloud)
GHOST = "client-z" + _uuid.uuid4().hex[:7]
GHOST_PREFIX = "zcl0"
GHOST_SEEN = []  # compte inexistant, pour détecter les oracles


# Profil alternatif (autre instance de test) : CLOISONS_PROFILE=/chemin/profile.json
import os as _os
if _os.environ.get("CLOISONS_PROFILE"):
    _p = json.load(open(_os.environ["CLOISONS_PROFILE"]))
    BASE = _p["base"]
    APPPW = json.load(open(_p["apppw"]))
    USERS = {k: tuple(v) for k, v in _p["users"].items()}
    ZONE = _p["zone"]
    ACTORS = _p["actors"]
    ADMINS = set(_p.get("admins", []))


def hidden_for(actor):
    """Comptes que `actor` ne doit jamais voir."""
    return [u for u in USERS if u != actor and ZONE[u] != ZONE[actor]]


def visible_for(actor):
    return [u for u in USERS if u != actor and ZONE[u] == ZONE[actor] and u != "bancadmin"]


def markers(uid):
    dn, mail = USERS[uid]
    m = [uid]
    if dn and dn != uid:
        m.append(dn)
    if mail:
        m.append(mail)
    return m


def find_leaks(actor, text, echo=()):
    """Identifiants de comptes cachés présents dans `text` (hors écho de la requête)."""
    t = text
    for e in sorted(echo, key=len, reverse=True):
        if e:
            t = t.replace(e, "\x00")
            t = t.replace(json.dumps(e)[1:-1], "\x00")
    found = []
    for u in hidden_for(actor):
        for m in markers(u):
            if m in t or json.dumps(m)[1:-1] in t:
                found.append(f"{u}:{m}")
    return found


class Client:
    def __init__(self, uid):
        self.uid = uid
        self.s = requests.Session()
        self.s.auth = (uid, APPPW[uid])
        self.s.headers.update({"OCS-APIRequest": "true", "Accept": "application/json"})
        self._tok = None

    def token(self):
        if not self._tok:
            r = self.s.get(BASE + "/csrftoken")
            self._tok = r.json().get("token")
        return self._tok

    def req(self, method, path, csrf=False, **kw):
        h = kw.pop("headers", {})
        if csrf:
            h["requesttoken"] = self.token()
        r = self.s.request(method, BASE + path, headers=h, timeout=60, allow_redirects=False, **kw)
        # limiteur de débit de Nextcloud : on attend puis on réessaie (jusqu'à 3 fois)
        import time as _t
        for _wait in (20, 60, 120):
            if r.status_code != 429:
                break
            _t.sleep(_wait)
            r = self.s.request(method, BASE + path, headers=h, timeout=60, allow_redirects=False, **kw)
        # un fantôme ne doit jamais apparaître dans une réponse
        if GHOST_PREFIX in r.text:
            GHOST_SEEN.append({"actor": self.uid, "method": method, "path": path.split("?")[0], "status": r.status_code,
                               "excerpt": r.text[max(0, r.text.find(GHOST_PREFIX) - 120):r.text.find(GHOST_PREFIX) + 60]})
        return r

    def ocs(self, method, path, **kw):
        sep = "&" if "?" in path else "?"
        return self.req(method, "/ocs/v2.php" + path + sep + "format=json", **kw)

    def dav(self, method, path, body=None, depth=None, ctype="application/xml; charset=utf-8", extra=None):
        h = {"Content-Type": ctype}
        if depth is not None:
            h["Depth"] = str(depth)
        if extra:
            h.update(extra)
        # sans cookie de session : authentification à chaque requête (cas le plus défavorable)
        r = requests.request(method, BASE + "/remote.php/dav" + path, auth=(self.uid, APPPW[self.uid]), headers=h,
                             data=body.encode() if isinstance(body, str) else body, timeout=60, allow_redirects=False)
        if GHOST_PREFIX in r.text:
            GHOST_SEEN.append({"actor": self.uid, "method": method, "path": "/remote.php/dav" + path, "status": r.status_code,
                               "excerpt": r.text[max(0, r.text.find(GHOST_PREFIX) - 120):r.text.find(GHOST_PREFIX) + 60]})
        return r


def norm(resp, *uids):
    """Normalise une réponse pour comparer existant/inexistant (oracle)."""
    t = resp.text
    for u in uids:
        t = t.replace(u, "<UID>")
    t = re.sub(r'"(reqId|requestId|time|timestamp|etag|id|token)"\s*:\s*"[^"]*"', '"\\1":"*"', t)
    t = re.sub(r"<s:message>.*?</s:message>", "", t)
    t = re.sub(r"[0-9a-f]{32,}", "<H>", t)
    t = re.sub(r'"(stime|item_source|file_source|file_parent|mail_send|item_mtime|storage|file_target|path|share_with_link|expiration|label|token|url|note|id)"\s*:\s*("[^"]*"|-?\d+|null)', r'"\1":"*"', t)
    t = re.sub(r'nonce="[^"]*"', 'nonce=""', t)
    t = re.sub(r'data-requesttoken="[^"]*"', '', t)
    t = re.sub(r'(requesttoken|oc_requesttoken)["\']?\s*[:=]\s*["\'][^"\']*["\']', r'\1=""', t)
    t = re.sub(r'<input type="hidden" id="initial-state-[^>]*>', '', t)
    return resp.status_code, t[:4000]
