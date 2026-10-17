# Fonctionnement

## Objectif

Sur une instance Nextcloud mutualisée, chaque personne ou structure accueillie
doit avoir **la même isolation que si elle disposait de sa propre instance** :
elle ne peut ni voir, ni rechercher, ni contacter, ni partager avec un compte
d'une autre cloison, dans aucune application, sans perdre aucune fonction dans
son propre espace.

## Groupes et cloisons

| Notion | Rôle |
|---|---|
| **Groupe** | Droit d'accès : ouvre une application (Office, Talk…) ou un service authentifié par OIDC. Inchangé par Cloisons. |
| **Cloison** | Visibilité : deux comptes ne se voient que s'ils sont dans la même cloison. Indépendante des groupes. |

- Un compte qui n'appartient à **aucune cloison partagée** est **seul dans sa
  cloison individuelle** (`u:<compte>`). Un oubli d'affectation isole donc par
  défaut.
- Une cloison partagée (`occ cloisons:zone create`) regroupe des comptes qui
  collaborent.
- Un compte appartient à une seule cloison.

## Aucun fichier de Nextcloud n'est modifié

| Couche | Point d'extension | Rôle |
|---|---|---|
| Partages | `BeforeShareCreatedEvent` | Refus serveur de tout partage hors cloison (compte, groupe mixte, équipe, fédération vers ce serveur), quelle que soit l'application d'origine. |
| Autocomplétion | `AutoCompleteFilterEvent` | Suggestions (mentions, partage) limitées à la cloison. |
| Listes de comptes | `UserEnumerationFilterEvent` (Nextcloud 35) | Comptes d'une autre cloison retirés. |
| API des applications | Middleware **global** | S'applique à tous les contrôleurs du cœur et des applications, selon la table de certification `lib/Service/Rules.php`. |
| WebDAV / CalDAV / CardDAV | Greffon Sabre (`SabrePluginAddEvent`) | Chemins, recherche de principaux, carnet système, partages, délégations, disponibilités, invitations, commentaires. |
| Carnet d'adresses système (PHP) | `IManager` des contacts | Remplacé par un carnet filtré par cloison. |
| Talk | `MessageParseEvent` | Une mention d'un compte d'une autre cloison reste du texte brut. |
| Activité d'administration | Mode incognito pendant les événements de compte | L'administrateur d'une autre cloison n'est pas nommé. |

### La table de certification

`lib/Service/Rules.php` liste, pour chaque route qui manipule des comptes :

- les **paramètres** qui désignent une personne (compte, liste de comptes,
  identifiant fédéré, e-mail, groupe, équipe) ;
- un traitement **avant** l'exécution (substitution, refus) ;
- un **filtre de la réponse** ;
- éventuellement l'exemption des administrateurs, l'application sans session
  (liens publics) et la version de Nextcloud où la route apparaît (`since`).

Une route absente de la table n'est pas modifiée : c'est pourquoi chaque
nouvelle version d'application doit être revue (voir
[CERTIFICATION.md](CERTIFICATION.md)).

### Le compte fantôme : caché = inexistant

Quand une requête désigne un compte d'une autre cloison, l'application
remplace, **avant l'exécution du contrôleur**, son identifiant par celui d'un
compte fantôme qui n'existe pas. Nextcloud répond alors exactement comme pour
un compte inexistant. La valeur d'origine est ensuite rétablie dans la réponse
et dans les messages d'erreur : aucun oracle d'existence.

- Chaque valeur cachée (compte, adresse, groupe, équipe) reçoit **son propre**
  fantôme, stable pour la cloison de l'auteur (préfixe `zcl0` + empreinte, même
  forme que l'original). Deux comptes cachés se comportent comme deux comptes
  inexistants différents.
- Quand une application conserve la valeur substituée (invitation Talk par
  e-mail, signataire LibreSign…), la correspondance est mémorisée
  (`oc_cloisons_ghosts`) et la valeur saisie est **rétablie dans toutes les
  réponses** faites à cette cloison, y compris les erreurs WebDAV.
- Les envois d'e-mails vers une adresse fantôme sont neutralisés sans erreur.

La substitution utilise `Request::setUrlParameters()` et le filtrage des
réponses OCS la propriété `data` de `BaseResponse`. Ces deux mécanismes internes
sont vérifiés par `occ cloisons:status` : s'ils disparaissaient dans une version
future, les requêtes concernées seraient **refusées** (jamais de fuite
silencieuse) et l'autotest échouerait.

### Corps de requête normalisés

Les corps WebDAV (partages d'agenda ou de carnet, disponibilités) sont analysés
après normalisation (entités XML, CDATA, repli de ligne iCalendar) : une
écriture détournée d'une adresse ne permet pas de contourner le filtre. Une
DTD est refusée.

### Liens publics d'une autre cloison

Sur une instance dédiée, une personne qui ouvre le lien public d'une autre
instance y est un invité anonyme. Sur une instance mutualisée, un compte
**connecté** y apparaîtrait sous son nom. L'accès lui est donc refusé (réponse
identique à un lien inexistant) ; le lien reste utilisable en navigation privée.

| Lien | Compte connecté d'une autre cloison |
|---|---|
| Fichier en lecture seule | Page, aperçu et téléchargement autorisés ; éditeurs (Text, ONLYOFFICE, tableau blanc) et discussion Talk attachée refusés |
| Fichier modifiable / dépôt | Refusé |
| Conversation Talk publique | Refusée (sauf s'il en est déjà participant) |
| Formulaire public, collectif public, agenda public, page de rendez-vous | Refusés |

### Groupe de la cloison

Les groupes d'accès (Office, Talk…) mêlent plusieurs cloisons : on ne peut plus
partager vers eux. Chaque cloison partagée dispose d'un **vrai groupe
Nextcloud** `cloison-<id>`, dont le nom affiché est celui de la cloison. Il est
tenu à jour automatiquement et utilisable partout comme cible. Tout ajout
manuel d'un compte extérieur à ce groupe est annulé immédiatement.

### Liens existants

`occ cloisons:scan` repère les liens existants :

- **croisés** : déjà établis entre deux cloisons ;
- **à risque** : vers un groupe d'accès. Nextcloud y donnerait accès au premier
  compte d'une autre cloison ajouté au groupe (partages, conversations Talk,
  agendas partagés à un groupe…). Ils doivent être convertis vers le groupe de
  la cloison **avant** d'ajouter une personne extérieure au groupe.

### Invitations d'agenda

Une invitation vers l'adresse d'un compte d'une autre cloison n'est jamais
livrée en interne (agenda, boîte de réception) : elle part par e-mail, comme
vers une adresse externe — exactement ce qui se passerait entre deux instances.

### Administrateur anonyme

Quand un administrateur crée, modifie ou ajoute à un groupe le compte d'une
autre cloison, l'activité générée chez ce compte ne le nomme pas.
`occ cloisons:repair-activity` corrige les activités antérieures à
l'installation.

## Modes

| Mode | Effet |
|---|---|
| `audit` (par défaut) | Toutes les décisions sont évaluées et journalisées, rien n'est modifié. Sert à valider sur une instance réelle sans risque. |
| `enforce` | Les décisions sont appliquées. |

## Journal

Chaque décision est consignée dans `oc_cloisons_events` (au plus une entrée par
heure pour un même acteur / vecteur / cible), visible dans *Paramètres
d'administration → Cloisons → Journal*. Purge quotidienne : 30 jours,
20 000 entrées au plus. Les erreurs graves (mécanisme indisponible) sont
écrites une fois par heure au plus dans le journal de Nextcloud ; les refus
ordinaires n'y écrivent rien.

## Organisation du code

```
cloisons/
  appinfo/info.xml
  lib/AppInfo/Application.php          enregistrement des couches
  lib/Service/ZoneService.php          appartenance, fantômes
  lib/Service/ZoneGroupService.php     groupes cloison-<id>
  lib/Service/Policy.php               mode, journal
  lib/Service/Rules.php                table de certification des routes
  lib/Service/Targets.php              identifiants fédérés, équipes, e-mails
  lib/Service/TalkHelper.php           rattachement des conversations Talk
  lib/Service/Scanner.php              liens entre cloisons
  lib/Service/HealthService.php        contrôles de santé, versions certifiées
  lib/Middleware/IsolationMiddleware.php  middleware global
  lib/Middleware/RuleContext.php       contexte d'une requête, substitutions
  lib/Middleware/PublicGuard.php       liens publics d'une autre cloison
  lib/Middleware/ResponseData.php      lecture/écriture des réponses
  lib/Dav/IsolationPlugin.php          greffon Sabre
  lib/Contacts/                        carnet système filtré
  lib/Listener/                        écouteurs d'événements
  lib/Command/                         occ cloisons:*
  lib/Controller/AdminController.php   API de l'écran d'administration
  src/                                 écran d'administration (Vue 3, @nextcloud/vue)
```
