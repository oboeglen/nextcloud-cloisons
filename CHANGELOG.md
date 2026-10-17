# Journal des versions

## 1.0.1 — 2026-10-17

Première publication.

- Isolation des comptes par cloisons, indépendante des groupes, dans toutes les
  applications certifiées (middleware global, écouteurs d'événements, greffon
  WebDAV, carnet système filtré).
- Compte fantôme : un compte caché est indiscernable d'un compte inexistant.
- Liens publics d'une autre cloison refusés aux comptes connectés.
- Groupe `cloison-<id>` synchronisé par cloison partagée.
- Modes `audit` et `enforce`, journal des décisions, écran d'administration.
- Commandes `occ cloisons:status|selftest|mode|zone|scan|repair-activity`.
- Certification : Nextcloud 34.0.4 (banc complet), Nextcloud 35.0.2 (revue de
  code des dernières versions des applications).
