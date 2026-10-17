# Politique de sécurité

## Versions maintenues

| Version | Nextcloud | Correctifs de sécurité |
|---|---|---|
| 1.0.x | 34, 35 | Oui |

## Signaler une vulnérabilité

Merci de **ne pas** ouvrir d'issue publique.

Utilisez le signalement privé de GitHub : onglet **Security** du dépôt →
**Report a vulnerability**. Indiquez :

- la version de Cloisons, de Nextcloud et des applications concernées ;
- le mode (`audit` / `enforce`) et le résultat de `occ cloisons:status` ;
- les étapes de reproduction, idéalement sur le banc de test (`docs/TESTS.md`),
  **sans données réelles**.

Est considérée comme une vulnérabilité toute possibilité, pour un compte d'une
cloison, de découvrir l'existence, l'identité ou les données d'un compte d'une
autre cloison, ou d'interagir avec lui (voir `docs/SECURITE.md`).
