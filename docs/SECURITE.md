# Sécurité

## Modèle de menace

**Protégé contre** : un compte accueilli (client, membre d'une autre structure)
qui, avec son propre identifiant, cherche à **découvrir**, **contacter** ou
**atteindre** les comptes ou les données d'une autre cloison, par l'interface
web, les applications mobiles et de bureau, l'API OCS, WebDAV/CalDAV/CardDAV ou
des requêtes construites à la main.

Concrètement, pour un compte d'une autre cloison, il doit être impossible :

- d'obtenir son identifiant, son nom, son adresse, son avatar, son statut ;
- de **savoir s'il existe** (aucune réponse différente d'un compte inexistant :
  code HTTP, message, contenu, déduplication des notifications) ;
- de lui partager quoi que ce soit, de l'inviter, de le mentionner, de
  l'ajouter à une conversation ou à une équipe ;
- de rejoindre, par un lien ou un code, un espace d'une autre cloison en
  apparaissant sous son nom de compte.

**Hors périmètre** :

- les **administrateurs** de l'instance et de l'hébergement (base de données,
  fichiers, journaux) : comme pour toute instance hébergée, il faut leur faire
  confiance ;
- les applications **non certifiées** (signalées par `occ cloisons:status`) ;
- les canaux extérieurs à Nextcloud (adresse e-mail connue par ailleurs,
  services tiers) ;
- l'isolation des **ressources** (quotas, charge, déni de service) : utilisez les
  quotas et limites natifs de Nextcloud.

## Garanties de conception

- **Fermé par défaut** : un compte sans affectation est seul dans sa cloison ;
  une erreur dans la vérification d'un lien ou d'un code entraîne un refus ;
  si un mécanisme interne de Nextcloud disparaît, les requêtes concernées sont
  refusées et l'autotest échoue.
- **Indistinguabilité** : caché et inexistant donnent la même réponse
  (fantômes distincts, même forme que l'original, valeurs rétablies dans les
  réponses et les erreurs).
- **Double barrière** : middleware global **et** écouteurs d'événements de
  Nextcloud (partages, autocomplétion, listes de comptes, mentions).
- **Corps normalisés** : entités XML, CDATA, repli iCalendar, DTD refusée.
- **Aucun fichier du cœur modifié** : les mises à jour de Nextcloud ne cassent
  pas silencieusement l'application ; elles sont détectées par l'autotest.

## Validation

La version 1.0.1 a été validée sur Nextcloud 34.0.4 par la batterie décrite
dans [TESTS.md](TESTS.md) : découverte (25 vecteurs de recherche × chaque paire
de comptes × 5 termes, oracles d'existence), actions croisées (partages, Talk,
agendas, équipes, formulaires, LibreSign, mentions, fédération locale…),
fonctions essentielles (~550 tests) et contournements (encodages, casse,
anciens points d'accès). Résultat : **0 fuite, 0 oracle, 0 action croisée,
0 régression** par rapport à la même instance sans l'application.

## Points d'attention pour l'exploitant

| Point | Action |
|---|---|
| Anciens points d'accès DAV | Fermés par le serveur web ([INSTALLATION.md](INSTALLATION.md)) |
| Partage fédéré sortant | Désactivé (réglages natifs recommandés) |
| Liens existants vers des groupes d'accès | `occ cloisons:scan` avant d'ouvrir un groupe à une nouvelle personne |
| Mode audit | Ne protège rien ; `enforce` en production |
| Nouvelles versions | Recertifier avant de les ouvrir aux comptes accueillis |
| API de partage unifiée (NC 35) | Laisser désactivée |
| Sondes manuelles | Jamais avec un vrai compte : `password_policy` peut le désactiver après quelques échecs |

## Signaler une vulnérabilité

Voir [SECURITY.md](../SECURITY.md). Merci de ne pas ouvrir d'issue publique pour
une faille.
