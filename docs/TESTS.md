# Tests

Cloisons est validée en comparant une instance **avec** l'application à la
même instance **sans** elle : tout ce qui marchait sans l'application doit
encore marcher (0 régression), et plus rien ne doit fuiter (0 fuite, 0 oracle,
0 action croisée).

> Les tests créent, modifient et suppriment des comptes et des données, et
> purgent des tables. **Ne les lancez jamais contre une instance de production.**

## Banc Docker

`bench/compose.yml` démarre un Nextcloud jetable (image officielle), MariaDB et
Redis, accessible uniquement sur `127.0.0.1:18980`, avec l'application montée
depuis `cloisons/`.

```bash
cd bench
cp .env.example .env            # puis remplacer par des mots de passe aléatoires
chmod 600 .env
printf '%s' 'MotDePasse-Test-1!' > conf/testpw && chmod 600 conf/testpw
docker compose up -d db redis   # attendre « ready for connections »
docker compose up -d app        # sinon l'installation échoue (base pas prête)
```

Puis, dans le conteneur (`docker exec -u www-data cloisons-banc-app-1 php occ …`) :

1. Installer les applications à couvrir, idéalement **les mêmes, aux mêmes
   versions, avec les mêmes restrictions de groupes** que l'instance à protéger
   (`app:install`, `app:enable --groups=…`).
2. Désactiver la protection anti-force brute et la limitation de débit **sur le
   banc uniquement** (les campagnes envoient des milliers de requêtes) :
   `config:system:set auth.bruteforce.protection.enabled --type=boolean --value=false`
   et `ratelimit.protection.enabled` idem.
3. Fermer les anciens points d'accès DAV dans l'Apache du conteneur
   (`RedirectMatch 404`, voir [INSTALLATION.md](INSTALLATION.md)) puis
   `config:app:set cloisons legacy_dav_blocked --value=yes --lazy`.
4. `app:enable cloisons`, `cloisons:mode enforce`.

### Comptes fictifs (`tests/seed_users.sh`)

| Compte | Cloison | Groupes |
|---|---|---|
| fam-anne | famille | admin + tous les groupes |
| fam-paul | famille | Domotique, IA, Office, Photos, Medias, Intranet, Talk |
| client-aaaaaaaa, client-bbbbbbbb | seuls | Office, Photos, Talk, Signatures, Coffre, IA |
| client-cccccccc | seul | Office |
| client-dddddddd | seul | aucun |

Les clients partagent volontairement les mêmes groupes d'accès que la famille :
c'est le cas le plus défavorable. Adaptez les noms de groupes aux restrictions
d'applications de votre banc (par exemple LibreSign limité à « Signatures »).

### Variables d'environnement

| Variable | Défaut | Rôle |
|---|---|---|
| `CLOISONS_BASE` | `http://127.0.0.1:18980` | Adresse du banc |
| `CLOISONS_CONF` | `bench/conf` | `testpw`, `apppw.json` |
| `CLOISONS_BENCH` | `bench` | Dossier contenant `.env` |
| `NC_ROOT` | `/var/www/html` | Racine Nextcloud pour les scripts PHP lancés dans le conteneur |
| `CLOISONS_PROFILE` | — | Fichier JSON décrivant d'autres comptes de test (`base`, `apppw`, `users`, `zone`, `actors`, `admins`) |

## Batteries

| Script | Contenu |
|---|---|
| `audit_discovery.py` | Découverte : 25 vecteurs de recherche × chaque paire × 5 termes, listes, 30 vecteurs ciblés par identifiant, **oracles** (compte caché ≠ compte inexistant). |
| `audit_actions.py` | Actions : partage (compte, groupe, fédéré, e-mail), transfert, Talk (1-1, participants, groupes, conversations ouvertes), invitations et disponibilités d'agenda, partages CalDAV/CardDAV, équipes, formulaires, mentions, absences. |
| `audit_actions2.py` | LibreSign, mentions Text/ONLYOFFICE, transfert de formulaire, propositions de réunion, délégation d'agenda, participants Talk à la création, invitation Talk fédérée vers ce serveur, ajout multiple aux équipes. |
| `audit_functions.py` | ~550 tests de **fonctions essentielles** pour chaque compte (fichiers, versions, corbeille, liens publics, agenda, contacts, Talk, formulaires, notes, équipes, collectifs, profil, Text…) et collaboration au sein de la famille. |
| `audit_fixes.py` | Contournements : entités XML, CDATA, repli iCalendar, casse, encodage d'URL, anciens points d'accès, mentions, aperçus de liens. |
| `check_rules.php` | Chaque règle vise une classe et une méthode existantes (dans le conteneur). |
| `routes_dump.php`, `routes_classify.php`, `scan_controllers.php` | Inventaire de toutes les routes et repérage des appels sensibles (certification). |
| `static/` | Indexation statique de deux versions de code ([CERTIFICATION.md](CERTIFICATION.md)). |
| `ui/shots.mjs` | Captures de l'écran d'administration (clair/sombre, 390 → 1440 px), erreurs console, débordements (Puppeteer). |

Chaque réponse est aussi inspectée par un détecteur global : un identifiant
fantôme (`zcl0…`) ne doit **jamais** apparaître.

## Lancer une campagne

```bash
tests/reset_bench.sh            # comptes fictifs, jetons, cloison « famille », purge
tests/baseline_all.sh           # mesures de référence SANS l'application → tests/out/baseline_*.json
tests/run_all.sh mon-etiquette  # campagne AVEC l'application → tests/out/mon-etiquette/
tests/reset_bench.sh && python3 tests/audit_fixes.py
```

`summarize.py` compare la campagne aux références :

- 0 `LEAK`, 0 `ORACLE`, 0 `CROSS`, 0 `GHOSTLEAK` ;
- aucun `FAIL`, `BROKEN` ou `MISS` qui n'existait pas déjà sans l'application
  (régression).

Dépendances : Docker, Python 3 avec `requests`.

## Purger le banc

```bash
cd bench && docker compose down -v
rm -rf html db conf/testpw conf/apppw.json .env ../tests/out
```
