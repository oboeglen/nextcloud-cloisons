# Installation

## Prérequis

- Nextcloud **34** ou **35** (voir [CERTIFICATION.md](CERTIFICATION.md)).
- PHP 8.2 ou plus récent.
- Accès à `occ` et à la configuration du serveur web (Apache ou nginx).

Dans les exemples :

```bash
NC=/var/www/nextcloud                      # racine de votre Nextcloud
OCC="sudo -u www-data php $NC/occ"
```

## 1. Copier l'application

Depuis ce dépôt, le dossier `cloisons/` contient l'application prête à
l'emploi (interface déjà compilée dans `cloisons/js/`) :

```bash
sudo rsync -a --exclude node_modules --exclude src cloisons/ $NC/apps/cloisons/
sudo chown -R www-data:www-data $NC/apps/cloisons
$OCC app:enable cloisons
```

L'application démarre en mode **audit** : rien n'est bloqué, tout est
journalisé.

## 2. Fermer les anciens points d'accès DAV (obligatoire)

`remote.php/caldav`, `carddav`, `calendar` et `contacts` sont d'anciens points
d'accès qui construisent leur propre serveur WebDAV **sans** déclencher
l'événement qui permet à une application d'y ajouter un greffon. Cloisons ne
peut donc pas les filtrer : ils doivent être fermés par le serveur web. Les
clients modernes (navigateur, DAVx⁵, Thunderbird, iOS/macOS) utilisent
`remote.php/dav`, qui n'est pas concerné.

### Apache

Dans le VirtualHost de Nextcloud :

```apache
RedirectMatch 404 "^/remote\.php/(caldav|carddav|calendar|contacts)(/|$)"
```

Utilisez **mod_alias** (`RedirectMatch`) : un `RewriteRule` ou un
`Require all denied` dans un bloc `<Location>` est contourné lorsque la requête
porte un en-tête `Authorization`, c'est-à-dire précisément par un client
authentifié.

### nginx

Dans le bloc `server` de Nextcloud, **avant** le bloc `location ~ \.php(?:$|/)`
(les blocs regex sont évalués dans l'ordre) :

```nginx
location ~ ^/remote\.php/(caldav|carddav|calendar|contacts)(/|$) {
    return 404;
}
```

### Vérifier puis déclarer

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://cloud.example.org/remote.php/caldav/
curl -s -o /dev/null -w '%{http_code}\n' -u inconnu:x https://cloud.example.org/remote.php/carddav/
# les deux doivent répondre 404

$OCC config:app:set cloisons legacy_dav_blocked --value=yes --lazy
```

> Testez toujours avec un identifiant **inexistant** : des échecs
> d'authentification répétés sur un vrai compte peuvent le désactiver
> (application `password_policy`).

Le drapeau est lu par `occ cloisons:status`. S'il est posé sans `--lazy`, il peut
rester invisible côté web tant que le cache APCu de PHP-FPM n'est pas vidé
(`systemctl reload php*-fpm`).

## 3. Réglages natifs recommandés

Défense en profondeur, indépendante de l'application (signalés par
`occ cloisons:status` s'ils diffèrent) :

```bash
$OCC config:app:set core shareapi_restrict_user_enumeration_to_group --value=yes
$OCC config:app:set core shareapi_restrict_user_enumeration_full_match --value=no
$OCC config:app:set core shareapi_only_share_with_group_members --value=yes
$OCC config:app:set core shareapi_allow_resharing --value=no
$OCC config:app:set files_sharing outgoing_server2server_share_enabled --value=no
$OCC config:app:set files_sharing outgoing_server2server_group_share_enabled --value=no
$OCC config:app:set systemtags restrict_creation_to_admin --value=1
```

Sur Nextcloud 35, laissez **désactivée** l'API de partage unifiée
expérimentale (`sharing.unified_api_enable` absent de `config.php`) : elle n'est
pas certifiée et Cloisons la rend introuvable aux non-administrateurs si elle
est activée.

## 4. Créer les cloisons partagées

```bash
$OCC cloisons:zone create famille "Famille"
$OCC cloisons:zone add famille anne
$OCC cloisons:zone add famille paul
$OCC cloisons:zone list
```

Tout compte non affecté est seul dans sa cloison.

## 5. Vérifier les liens existants

```bash
$OCC cloisons:scan
```

Convertissez chaque élément signalé (partage vers un groupe d'accès,
conversation Talk ouverte à un groupe, agenda partagé à un groupe…) vers le
groupe de la cloison concernée, `cloison-<id>`.

## 6. Observer, puis appliquer

1. Laissez tourner en mode audit et consultez *Paramètres d'administration →
   Cloisons → Journal* : chaque ligne est une action qui **serait** bloquée.
2. Quand le journal ne montre que des tentatives attendues :

```bash
$OCC cloisons:selftest
$OCC cloisons:mode enforce
```

3. Facultatif : `$OCC cloisons:repair-activity --dry-run` puis sans
   `--dry-run` pour anonymiser l'administrateur dans les activités antérieures.

## Mise à jour de l'application

```bash
sudo rsync -a --exclude node_modules --exclude src cloisons/ $NC/apps/cloisons/
sudo chown -R www-data:www-data $NC/apps/cloisons
$OCC upgrade                 # seulement si la version d'info.xml a changé (migrations)
sudo systemctl reload apache2   # ou php*-fpm : vide le cache des routes
$OCC cloisons:selftest
```

## Compiler l'interface (développeurs)

L'interface d'administration (Vue 3, `@nextcloud/vue`) est déjà compilée dans
`cloisons/js/`. Pour la reconstruire (Node.js 24 recommandé) :

```bash
cd cloisons
npm ci
npm run build
```

Sans Node.js local :

```bash
docker run --rm -v "$PWD/cloisons:/app" -w /app node:24-alpine sh -c "npm ci && npm run build"
```

## Désinstallation

```bash
$OCC app:disable cloisons     # retour immédiat au comportement natif
$OCC app:remove cloisons      # supprime les fichiers de l'application
```

Les tables `oc_cloisons_*`, les valeurs `cloisons` de `oc_appconfig` et les
groupes `cloison-<id>` peuvent subsister : supprimez-les à la main si vous
n'envisagez pas de réinstaller l'application.
