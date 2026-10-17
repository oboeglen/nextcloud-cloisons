<?php

declare(strict_types=1);

namespace OCA\Cloisons\Service;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ServerVersion;

/**
 * Contrôles de santé : version certifiée, mécanismes disponibles, réglages de
 * défense en profondeur, applications ouvertes aux clients sans certification.
 */
class HealthService {
	/**
	 * Versions de Nextcloud validées (majeure). 34 : batterie de tests complète
	 * (banc du 2026-10-16). 35 : revue de code de 35.0.2 et des dernières
	 * versions des applications (2026-10-17, docs/CERTIFICATION-NC35.md).
	 */
	public const CERTIFIED_SERVER = [34, 35];

	/**
	 * Applications dont Cloisons filtre réellement des routes (table Rules) ou des
	 * greffons DAV : leur VERSION EXACTE est épinglée. Une mise à jour, même
	 * mineure, peut ajouter une route ou changer une réponse → il faut rejouer la
	 * batterie de tests avant de la certifier à nouveau. Un écart est signalé.
	 */
	public const PINNED_APPS = [
		'dav' => ['1.40.0', '2.0.0'],
		'spreed' => ['24.0.5', '25.0.6'],
		'circles' => ['34.0.0', '35.0.0'],
		'calendar' => ['6.6.2'],
		'files_sharing' => ['1.26.0', '2.0.0'],
		'forms' => ['5.4.0'],
		'libresign' => ['14.2.5', '15.0.8'],
		'onlyoffice' => ['10.2.1'],
		'contacts' => ['8.9.1'],
		'provisioning_api' => ['1.24.0', '2.0.0'],
		'text' => ['8.0.0', '9.0.1'],
		'user_status' => ['1.14.0', '2.0.0'],
		'dashboard' => ['7.14.0', '8.0.0'],
		'end_to_end_encryption' => ['2.5.0'],
		'profile' => ['1.3.0', '2.0.0'],
		'bookmarks' => ['16.2.7', '17.0.0'],
		'comments' => ['1.24.0', '2.0.0'],
	];

	/**
	 * Applications dont toutes les routes accessibles aux utilisateurs ont été
	 * revues et testées, avec la branche de version certifiée.
	 * Une application absente ou d'une autre branche est signalée.
	 */
	public const CERTIFIED_APPS = [
		'activity' => ['7.', '8.'],
		'app_api' => ['34.', '35.'],
		'appstore' => ['1.', '2.'],
		'assistant' => ['3.', '4.'],
		'bookmarks' => ['16.', '17.'],
		'bruteforcesettings' => ['7.', '8.'],
		'calendar' => ['6.'],
		'circles' => ['34.', '35.'],
		'cloisons' => ['1.'],
		'cloud_federation_api' => ['1.', '2.'],
		'collectives' => ['4.'],
		'comments' => ['1.', '2.'],
		'contacts' => ['8.'],
		'contactsinteraction' => ['1.', '2.'],
		'context_chat' => ['5.'],
		'dashboard' => ['7.', '8.'],
		'dav' => ['1.', '2.'],
		'end_to_end_encryption' => ['2.'],
		'external' => ['9.', '10.'],
		'federatedfilesharing' => ['1.', '2.'],
		'federation' => ['1.', '2.'],
		'files' => ['2.', '3.'],
		'files_3dmodelviewer' => ['0.'],
		'files_accesscontrol' => ['5.', '6.'],
		'files_automatedtagging' => ['5.', '6.'],
		'files_downloadlimit' => ['5.'],
		'files_external' => ['1.', '2.'],
		'files_lock' => ['34.', '35.'],
		'files_pdfviewer' => ['7.', '8.'],
		'files_reminders' => ['1.', '2.'],
		'files_sharing' => ['1.', '2.'],
		'files_trashbin' => ['1.', '2.'],
		'files_versions' => ['1.', '2.'],
		'flow_notifications' => ['5.', '6.'],
		'forms' => ['5.'],
		'impersonate' => ['5.', '6.'],
		'integration_github' => ['3.'],
		'integration_google' => ['4.'],
		'integration_immich' => ['1.'],
		'integration_openai' => ['4.', '5.'],
		'libresign' => ['14.', '15.'],
		'logreader' => ['7.', '8.'],
		'lookup_server_connector' => ['1.', '2.'],
		'music' => ['3.'],
		'notes' => ['6.'],
		'notifications' => ['7.', '8.'],
		'notify_push' => ['1.'],
		'oauth2' => ['1.', '2.'],
		'oidc' => ['2.'],
		'onlyoffice' => ['10.'],
		'ownershiptransfer' => ['1.'],
		'password_policy' => ['6.', '7.'],
		'previewgenerator' => ['5.'],
		'profile' => ['1.', '2.'],
		'provisioning_api' => ['1.', '2.'],
		'quota_warning' => ['1.'],
		'recommendations' => ['7.', '8.'],
		'serverinfo' => ['6.', '7.'],
		'settings' => ['1.', '2.'],
		'share_audit_dashboard' => ['0.'],
		'sharebymail' => ['1.', '2.'],
		'socialsharing_email' => ['4.', '5.'],
		'spreed' => ['24.', '25.'],
		'suspicious_login' => ['12.', '13.'],
		'systemtags' => ['1.', '2.'],
		'talk_matterbridge' => ['1.'],
		'tasks' => ['0.'],
		'text' => ['8.', '9.'],
		'theming' => ['2.', '3.'],
		'theming_customcss' => ['1.'],
		'twofactor_admin' => ['4.'],
		'twofactor_backupcodes' => ['1.', '2.'],
		'twofactor_email' => ['3.'],
		'twofactor_nextcloud_notification' => ['8.', '9.'],
		'twofactor_totp' => ['16.', '17.'],
		'twofactor_webauthn' => ['2.'],
		'user_migration' => ['10.'],
		'user_status' => ['1.', '2.'],
		'viewer' => ['7.', '8.'],
		'weather_status' => ['1.', '2.'],
		'webhook_listeners' => ['1.', '2.'],
		'whiteboard' => ['2.'],
		'workflow_pdf_converter' => ['5.', '6.'],
		'workflowengine' => ['2.', '3.'],
		'sharing' => ['1.'], // livrée à partir de Nextcloud 35 (API de partage unifiée, désactivée par défaut)
	];

	/** Réglages natifs recommandés (défense en profondeur, indépendants de l'application). */
	public const RECOMMENDED = [
		['core', 'shareapi_restrict_user_enumeration_to_group', 'yes'],
		['core', 'shareapi_restrict_user_enumeration_full_match', 'no'],
		['core', 'shareapi_only_share_with_group_members', 'yes'],
		['files_sharing', 'outgoing_server2server_share_enabled', 'no'],
		['files_sharing', 'outgoing_server2server_group_share_enabled', 'no'],
		['systemtags', 'restrict_creation_to_admin', '1'],
		['core', 'shareapi_allow_resharing', 'no'],
	];

	public function __construct(
		private Policy $policy,
		private ZoneService $zones,
		private Rules $rules,
		private IAppManager $appManager,
		private IAppConfig $appConfig,
		private IConfig $config,
		private IGroupManager $groupManager,
		private IRequest $request,
		private ServerVersion $serverVersion,
		private Scanner $scanner,
	) {
	}

	/** @return array{ok: bool, mode: string, checks: list<array{id: string, level: string, label: string, detail: string}>} */
	public function report(): array {
		$checks = [];
		$add = function (string $id, string $level, string $label, string $detail) use (&$checks) {
			$checks[] = compact('id', 'level', 'label', 'detail');
		};

		$major = $this->serverVersion->getMajorVersion();
		$add('server', in_array($major, self::CERTIFIED_SERVER, true) ? 'ok' : 'error', 'Version de Nextcloud',
			$this->serverVersion->getVersionString() . (in_array($major, self::CERTIFIED_SERVER, true) ? ' (certifiée)' : ' — non certifiée : relancer la batterie de tests'));

		// Points d'accès DAV hérités (remote.php/caldav|carddav|calendar|contacts) :
		// ils construisent leur propre serveur Sabre SANS déclencher l'événement
		// qui permet à une application d'y injecter un greffon → Cloisons ne peut
		// pas les filtrer. Ils doivent être fermés au niveau d'Apache. Le drapeau
		// est posé par le déploiement après vérification (curl → 404).
		$legacy = $this->appConfig->getValueString('cloisons', 'legacy_dav_blocked', '', lazy: true);
		$add('legacy-dav', $legacy === 'yes' ? 'ok' : 'error', 'Points d\'accès DAV hérités',
			$legacy === 'yes'
				? 'Fermés au niveau d\'Apache (remote.php/caldav, carddav, calendar, contacts → 404)'
				: 'NON vérifiés : ces points d\'accès contournent le filtrage. Fermer dans le vHost puis relancer le déploiement.');

		$add('substitution', method_exists($this->request, 'setUrlParameters') ? 'ok' : 'error', 'Masquage des comptes',
			method_exists($this->request, 'setUrlParameters') ? 'Mécanisme disponible' : 'Indisponible : les requêtes concernées sont refusées');

		$add('ocs', \OCA\Cloisons\Middleware\ResponseData::supported() ? 'ok' : 'error', 'Filtrage des réponses OCS',
			\OCA\Cloisons\Middleware\ResponseData::supported() ? 'Mécanisme disponible' : 'Indisponible : les listes ne sont plus filtrées');

		$anon = \OCA\Cloisons\Listener\AnonymousAdminListener::supported();
		$add('anonymous-admin', $anon ? 'ok' : 'error', 'Administrateur anonyme pour les clients',
			$anon ? 'Mécanisme disponible' : 'Indisponible : l\'administrateur serait nommé dans l\'activité des clients');

		$add('mode', $this->policy->enforcing() ? 'ok' : 'warning', 'Mode',
			$this->policy->enforcing() ? 'Blocage actif' : 'Audit : les décisions sont journalisées mais pas appliquées');

		// règles orphelines (classe ou méthode absente)
		$missing = [];
		$applicable = 0;
		foreach ($this->rules->certified() as $key) {
			if ($major < $this->rules->since($key)) {
				continue; // route d'une version plus récente de Nextcloud
			}
			$applicable++;
			[$class, $method] = explode('::', $key);
			if (!class_exists($class)) {
				continue; // application absente ou désactivée
			}
			$found = false;
			foreach ((new \ReflectionClass($class))->getMethods() as $m) {
				if (strtolower($m->getName()) === $method) {
					$found = true;
					break;
				}
			}
			if (!$found) {
				$missing[] = $key;
			}
		}
		$add('rules', $missing === [] ? 'ok' : 'error', 'Routes certifiées',
			$missing === [] ? $applicable . ' routes sous contrôle' : 'Introuvables : ' . implode(', ', $missing));

		// applications ouvertes à des comptes hors cloison nommée, non certifiées
		$uncertified = [];
		$outdated = [];
		foreach ($this->appManager->getEnabledApps() as $app) {
			$version = (string)$this->appManager->getAppVersion($app);
			if (!array_key_exists($app, self::CERTIFIED_APPS)) {
				$uncertified[] = $app . ' ' . $version;
			} elseif (!array_filter(self::CERTIFIED_APPS[$app], fn ($b) => str_starts_with($version, $b))) {
				$outdated[] = $app . ' ' . $version . ' (certifiée : ' . implode('x, ', self::CERTIFIED_APPS[$app]) . 'x)';
			}
		}
		$add('apps', $uncertified === [] ? 'ok' : 'error', 'Applications non certifiées',
			$uncertified === [] ? 'Aucune' : implode(', ', $uncertified));
		$add('apps_versions', $outdated === [] ? 'ok' : 'warning', 'Versions à recertifier',
			$outdated === [] ? 'Aucune' : implode(', ', $outdated));

		// versions exactes épinglées des applications réellement filtrées
		$pinned = [];
		foreach (self::PINNED_APPS as $app => $want) {
			if (!$this->appManager->isEnabledForUser($app)) {
				continue;
			}
			$have = (string)$this->appManager->getAppVersion($app);
			if (!in_array($have, $want, true)) {
				$pinned[] = $app . ' ' . $have . ' (certifiée : ' . implode(', ', $want) . ')';
			}
		}
		$add('apps_pinned', $pinned === [] ? 'ok' : 'warning', 'Applications filtrées à recertifier',
			$pinned === [] ? 'Aucune' : implode(', ', $pinned) . ' — rejouer tests/run_all.sh sur le banc');

		// API de partage unifiée (Nextcloud 35, expérimentale, désactivée par
		// défaut) : aucun type de destinataire n'y est filtré par Cloisons
		$unified = $this->config->getSystemValueBool('sharing.unified_api_enable', false);
		$add('unified-sharing', $unified ? 'error' : 'ok', 'API de partage unifiée',
			$unified ? 'Activée (sharing.unified_api_enable) : non certifiée, la désactiver dans config.php' : 'Désactivée');

		$bad = [];
		foreach (self::RECOMMENDED as [$app, $key, $want]) {
			$value = (string)$this->config->getAppValue($app, $key, '');
			$norm = fn ($v) => in_array(strtolower($v), ['1', 'yes', 'true'], true) ? 'yes' : (in_array(strtolower($v), ['0', 'no', 'false', ''], true) ? 'no' : $v);
			if ($norm($value) !== $norm($want)) {
				$bad[] = "$app/$key=" . ($value === '' ? '(défaut)' : $value) . " (recommandé : $want)";
			}
		}
		$add('native', $bad === [] ? 'ok' : 'warning', 'Réglages natifs', $bad === [] ? 'Conformes' : implode(' ; ', $bad));

		$items = $this->scanner->scan();
		$cross = count(array_filter($items, fn ($i) => $i['level'] === 'error'));
		$risk = count($items) - $cross;
		$add('links', $cross > 0 ? 'error' : ($risk > 0 ? 'warning' : 'ok'), 'Liens entre cloisons',
			$cross > 0 ? $cross . ' lien(s) croisé(s) existant(s) — voir occ cloisons:scan'
				: ($risk > 0 ? $risk . ' partage(s) vers un groupe d\'accès, à convertir vers le groupe de la cloison avant d\'y ajouter un client' : 'Aucun'));

		$named = $this->zones->namedMembers();
		$admins = $this->groupManager->get('admin')?->getUsers() ?? [];
		$lonelyAdmins = array_values(array_filter(array_map(fn ($u) => $u->getUID(), $admins),
			fn ($uid) => str_starts_with($this->zones->zoneOf($uid), ZoneService::INDIVIDUAL_PREFIX)));
		$add('zones', 'ok', 'Cloisons nommées', count($named) . ' cloison(s), '
			. array_sum(array_map('count', $named)) . ' membre(s)'
			. ($lonelyAdmins ? ' — administrateurs isolés : ' . implode(', ', $lonelyAdmins) : ''));

		$ok = !in_array('error', array_column($checks, 'level'), true);
		return ['ok' => $ok, 'mode' => $this->policy->mode(), 'checks' => $checks];
	}
}
