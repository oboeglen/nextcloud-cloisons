# Certification des versions

Cloisons filtre les routes qui manipulent des comptes, application par
application (`lib/Service/Rules.php`). Une nouvelle version de Nextcloud ou
d'une application peut ajouter une route, en renommer une ou changer le format
d'une réponse : chaque version est donc **certifiée** avant d'être acceptée
sans alerte par `occ cloisons:status`.

## Versions certifiées (1.0.1)

| Nextcloud | Méthode |
|---|---|
| **34.0.4** | Banc de test complet : 0 fuite, 0 oracle, 0 action croisée, 0 régression |
| **35.0.2** | Revue de code de 35.0.2 et des dernières versions des applications (2026-10-17). La campagne complète sur banc reste à rejouer avant une mise en production. |

### Applications filtrées : version exacte

| Application | Nextcloud 34 | Nextcloud 35 |
|---|---|---|
| dav | 1.40.0 | 2.0.0 |
| spreed (Talk) | 24.0.5 | 25.0.6 |
| circles (Équipes) | 34.0.0 | 35.0.0 |
| calendar | 6.6.2 | 6.6.2 |
| files_sharing | 1.26.0 | 2.0.0 |
| forms | 5.4.0 | 5.4.0 |
| libresign | 14.2.5 | 15.0.8 |
| onlyoffice | 10.2.1 | 10.2.1 |
| contacts | 8.9.1 | 8.9.1 |
| provisioning_api | 1.24.0 | 2.0.0 |
| text | 8.0.0 | 9.0.1 |
| user_status | 1.14.0 | 2.0.0 |
| dashboard | 7.14.0 | 8.0.0 |
| end_to_end_encryption | 2.5.0 | 2.5.0 |
| profile | 1.3.0 | 2.0.0 |
| bookmarks | 16.2.7 | 17.0.0 |
| comments | 1.24.0 | 2.0.0 |

### Autres applications : branche de version

Toutes les routes accessibles aux utilisateurs ont été revues pour les
branches listées dans `HealthService::CERTIFIED_APPS`, notamment : activity,
assistant, collectives, context_chat, files, files_versions, files_trashbin,
integration_openai, music, notes, notifications, oidc, tasks, twofactor_*,
user_migration, viewer, whiteboard… (83 applications en plus de Cloisons, et `sharing`
livrée avec Nextcloud 35).

Une application **absente** de cette liste est une erreur de l'autotest : elle
n'a pas été examinée.

## Changements de Nextcloud 35 pris en compte

| Changement | Traitement |
|---|---|
| `AutoCompleteFilterEvent` émis aussi pour la recherche de destinataires et les mentions Text | Déjà filtré par l'écouteur : double barrière cohérente |
| Nouvel événement `UserEnumerationFilterEvent` | Écouteur dédié |
| Activity 8 : filtre `actor`, histogramme | Compte caché remplacé par un fantôme |
| Profile 2 : ressources partagées avec un compte | Compte caché remplacé par un fantôme |
| Circles 35 : liens d'invitation d'équipe | Code d'une équipe d'une autre cloison = code inconnu ; résolution en base, refus en cas d'erreur |
| Application `sharing` (API de partage unifiée, expérimentale, désactivée par défaut) | Contrôle de santé + introuvable pour les non-administrateurs si activée |
| Talk 25 (conversations classifiées, canaux, annonces) | Ajouts de participants déjà filtrés |
| LibreSign 15 (politiques) | Signataires toujours filtrés ; politiques réservées aux administrateurs |

Les règles propres à Nextcloud 35 portent `'since' => 35` : l'autotest les
ignore sur Nextcloud 34, où les routes n'existent pas.

## Certifier une nouvelle version

1. **Inventaire statique** (sans exécuter Nextcloud). Indexez l'ancien et le
   nouveau code, puis comparez :

   ```bash
   php tests/static/index_code.php ancien.json /chemin/ancien/{core,lib,apps/*}
   php tests/static/index_code.php nouveau.json /chemin/nouveau/{core,lib,apps/*}
   ```

   Chaque classe/méthode est indexée avec ses paramètres, ses attributs de
   route (`#[NoAdminRequired]`, `#[PublicPage]`…) et une empreinte de son corps.
   À vérifier :
   - chaque règle de `Rules.php` vise une méthode qui existe encore, avec les
     mêmes noms de paramètres ;
   - le diff de chaque méthode filtrée dont le corps a changé
     (`php tests/static/method_source.php <fichier> <méthode>` extrait une
     méthode) ;
   - chaque **nouvelle** méthode publique de contrôleur accessible à un
     utilisateur ou anonymement : manipule-t-elle un compte, un groupe, une
     équipe, une adresse ?
   - les API internes utilisées par Cloisons (voir
     [FONCTIONNEMENT.md](FONCTIONNEMENT.md)).
2. **Règles** : ajoutez les routes nouvelles dans `Rules.php` (avec `'since'`
   si elles n'existent qu'à partir d'une version de Nextcloud).
3. **Banc** : rejouez la campagne ([TESTS.md](TESTS.md)) avec les nouvelles
   versions. Verdict attendu : 0 fuite, 0 oracle, 0 action croisée,
   0 régression.
4. **Déclarez** la version dans `HealthService::PINNED_APPS` (version exacte) ou
   `CERTIFIED_APPS` (branche), et la version majeure de Nextcloud dans
   `CERTIFIED_SERVER`.

Les contributions de certifications (nouvelles applications, nouvelles
versions) sont bienvenues : joignez le résultat de la campagne.
