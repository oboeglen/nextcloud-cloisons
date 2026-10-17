# Commandes `occ`

```bash
OCC="sudo -u www-data php /var/www/nextcloud/occ"
```

Toutes les commandes en lecture seule (`status`, `selftest`, `scan`, `zone list`,
`zone show`) peuvent être lancées à tout moment, y compris en production.

## `cloisons:status`

État de santé du cloisonnement.

```bash
$OCC cloisons:status
$OCC cloisons:status --output=json    # pour la supervision
```

Code retour **1** si un contrôle est en erreur. Contrôles effectués :

| Contrôle | Erreur si… |
|---|---|
| Version de Nextcloud | version majeure non certifiée |
| Points d'accès DAV hérités | `legacy_dav_blocked` n'est pas posé |
| Masquage des comptes / filtrage OCS / administrateur anonyme | mécanisme interne de Nextcloud indisponible |
| Mode | (avertissement) mode audit |
| Routes certifiées | une règle vise une classe ou une méthode disparue |
| Applications non certifiées | une application activée est absente de la liste |
| Versions à recertifier | (avertissement) branche de version non certifiée |
| Applications filtrées à recertifier | (avertissement) version exacte non testée d'une application filtrée |
| API de partage unifiée | activée (Nextcloud 35) |
| Réglages natifs | (avertissement) réglage recommandé différent |
| Liens entre cloisons | lien croisé existant ; (avertissement) lien à risque |
| Cloisons nommées | (information) nombre de cloisons, administrateurs isolés |

## `cloisons:selftest`

Autotest en lecture seule : tous les contrôles de `status`, plus l'absence du
compte fantôme et l'existence des membres de chaque cloison. À lancer après
chaque mise à jour de Nextcloud ou d'une application.

```bash
$OCC cloisons:selftest && echo OK
```

## `cloisons:mode`

```bash
$OCC cloisons:mode            # affiche : audit ou enforce
$OCC cloisons:mode audit      # journalise sans bloquer
$OCC cloisons:mode enforce    # applique le cloisonnement
```

## `cloisons:zone`

Gère les cloisons **partagées**. Un compte qui n'est dans aucune d'elles est seul
dans sa cloison.

```bash
$OCC cloisons:zone list [--output=json]
$OCC cloisons:zone create <id> "<Nom affiché>"   # id : a-z, 0-9, _ et -, 63 car. max
$OCC cloisons:zone rename <id> "<Nouveau nom>"
$OCC cloisons:zone delete <id>                   # ses membres redeviennent seuls
$OCC cloisons:zone add <id> <compte>             # un compte n'a qu'une cloison
$OCC cloisons:zone remove <id> <compte>
$OCC cloisons:zone show <compte>                 # ex. « anne → famille », « bob → u:bob »
```

Chaque modification resynchronise le groupe `cloison-<id>`.

Exemple : une association de trois bénévoles et deux clients indépendants.

```bash
$OCC cloisons:zone create asso-velo "Association Vélo"
for u in marie lucas ines; do $OCC cloisons:zone add asso-velo $u; done
# les deux clients n'ont besoin d'aucune commande : ils sont déjà isolés
```

## `cloisons:scan`

Liste les liens existants entre cloisons (lecture seule) : partages de
fichiers (compte, groupe, équipe, fédération locale), conversations Talk,
agendas et carnets partagés, délégations d'agenda, équipes, formulaires,
dossiers de signets. Niveau **erreur** : lien déjà croisé ; **avertissement** :
lien vers un groupe d'accès, à convertir vers le groupe `cloison-<id>` avant
d'ajouter une personne extérieure à ce groupe.

```bash
$OCC cloisons:scan
$OCC cloisons:scan --output=json
```

## `cloisons:repair-activity`

Anonymise, dans les activités déjà enregistrées, l'administrateur d'une autre
cloison (« X vous a ajouté au groupe… »). Utile une fois, après l'installation.

```bash
$OCC cloisons:repair-activity --dry-run   # affiche sans modifier
$OCC cloisons:repair-activity
```

## Réglages de l'application

| Clé | Valeur | Rôle |
|---|---|---|
| `cloisons mode` | `audit` / `enforce` | Préférez `occ cloisons:mode` |
| `cloisons legacy_dav_blocked` | `yes` (lazy) | Déclare la fermeture des anciens points d'accès DAV |

```bash
$OCC config:app:set cloisons legacy_dav_blocked --value=yes --lazy
```

## Désactivation d'urgence

```bash
$OCC cloisons:mode audit      # plus rien n'est bloqué, tout reste journalisé
$OCC app:disable cloisons     # retour immédiat au comportement natif
```
