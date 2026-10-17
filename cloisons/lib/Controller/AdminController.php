<?php

declare(strict_types=1);

namespace OCA\Cloisons\Controller;

use OCA\Cloisons\AppInfo\Application;
use OCA\Cloisons\Service\HealthService;
use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\Rules;
use OCA\Cloisons\Service\ZoneService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * API de l'écran d'administration. Réservée aux administrateurs (aucun
 * attribut NoAdminRequired) ; les changements sensibles demandent la
 * confirmation du mot de passe.
 */
class AdminController extends OCSController {
	public function __construct(
		IRequest $request,
		private ZoneService $zones,
		private Policy $policy,
		private HealthService $health,
		private Rules $rules,
		private IUserManager $userManager,
		private IDBConnection $db,
		private \OCA\Cloisons\Service\ZoneGroupService $groups,
		private \OCA\Cloisons\Service\Scanner $scanner,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userInfo(string $uid): array {
		$u = $this->userManager->get($uid);
		return ['uid' => $uid, 'displayName' => $u?->getDisplayName() ?? $uid, 'exists' => $u !== null];
	}

	#[ApiRoute(verb: 'GET', url: '/api/v1/state')]
	public function state(): DataResponse {
		$members = $this->zones->namedMembers();
		$zones = [];
		foreach ($this->zones->namedZones() as $z) {
			$zones[] = [
				'id' => $z['id'],
				'name' => $z['name'],
				'members' => array_map(fn ($uid) => $this->userInfo($uid), $members[$z['id']] ?? []),
			];
		}
		$counts = $this->zones->countAccounts();
		return new DataResponse([
			'mode' => $this->policy->mode(),
			'health' => $this->health->report(),
			'zones' => $zones,
			'counts' => $counts,
			'rules' => count($this->rules->certified()),
		]);
	}

	#[PasswordConfirmationRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/mode')]
	public function setMode(string $mode): DataResponse {
		try {
			$this->policy->setMode($mode);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse(['mode' => $this->policy->mode()]);
	}

	#[ApiRoute(verb: 'POST', url: '/api/v1/zones')]
	public function createZone(string $id, string $name): DataResponse {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 120) {
			return new DataResponse(['message' => 'Nom invalide'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$this->zones->createZone($id, $name);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->groups->syncAll();
		return $this->state();
	}

	#[ApiRoute(verb: 'PUT', url: '/api/v1/zones/{id}')]
	public function renameZone(string $id, string $name): DataResponse {
		$name = trim($name);
		if (!$this->zones->zoneExists($id) || $name === '' || mb_strlen($name) > 120) {
			return new DataResponse(['message' => 'Nom invalide'], Http::STATUS_BAD_REQUEST);
		}
		$this->zones->renameZone($id, $name);
		$this->groups->syncAll();
		return $this->state();
	}

	#[PasswordConfirmationRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/zones/{id}')]
	public function deleteZone(string $id): DataResponse {
		if (!$this->zones->zoneExists($id)) {
			return new DataResponse(['message' => 'Cloison inconnue'], Http::STATUS_NOT_FOUND);
		}
		$this->zones->deleteZone($id);
		$this->groups->syncAll();
		return $this->state();
	}

	#[PasswordConfirmationRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/zones/{id}/members')]
	public function addMember(string $id, string $uid): DataResponse {
		if (!$this->zones->zoneExists($id) || !$this->userManager->userExists($uid)) {
			return new DataResponse(['message' => 'Cloison ou compte inconnu'], Http::STATUS_BAD_REQUEST);
		}
		$this->zones->assign($uid, $id);
		$this->groups->syncAll();
		return $this->state();
	}

	#[PasswordConfirmationRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/zones/{id}/members/{uid}')]
	public function removeMember(string $id, string $uid): DataResponse {
		if ($this->zones->zoneOf($uid) === $id) {
			$this->zones->assign($uid, null);
		}
		$this->groups->syncAll();
		return $this->state();
	}

	/** Recherche de comptes pour le sélecteur (identifiant ou nom affiché). */
	#[ApiRoute(verb: 'GET', url: '/api/v1/users')]
	public function searchUsers(string $search = ''): DataResponse {
		$out = [];
		foreach ($this->userManager->searchDisplayName(trim($search), 20) as $u) {
			$zone = $this->zones->zoneOf($u->getUID());
			$out[] = ['uid' => $u->getUID(), 'displayName' => $u->getDisplayName(),
				'zone' => str_starts_with($zone, ZoneService::INDIVIDUAL_PREFIX) ? null : $zone];
		}
		return new DataResponse($out);
	}

	/** Cloison d'un compte et comptes qu'il peut voir. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/users/{uid}')]
	public function inspectUser(string $uid): DataResponse {
		if (!$this->userManager->userExists($uid)) {
			return new DataResponse(['message' => 'Compte inconnu'], Http::STATUS_NOT_FOUND);
		}
		$zone = $this->zones->zoneOf($uid);
		$named = !str_starts_with($zone, ZoneService::INDIVIDUAL_PREFIX);
		$zoneName = null;
		foreach ($this->zones->namedZones() as $z) {
			if ($z['id'] === $zone) {
				$zoneName = $z['name'];
			}
		}
		return new DataResponse([
			'user' => $this->userInfo($uid),
			'zone' => $named ? ($zoneName ?? $zone) : null,
			'peers' => array_map(fn ($p) => $this->userInfo($p), array_values(array_diff($this->zones->peers($uid), [$uid]))),
		]);
	}

	#[ApiRoute(verb: 'GET', url: '/api/v1/events')]
	public function events(int $limit = 50, int $offset = 0, string $actor = '', string $vector = ''): DataResponse {
		$limit = max(1, min(200, $limit));
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'created_at', 'actor', 'vector', 'target', 'mode')->from(Policy::TABLE_EVENTS)
			->orderBy('id', 'DESC')->setMaxResults($limit + 1)->setFirstResult(max(0, $offset));
		if ($actor !== '') {
			$qb->andWhere($qb->expr()->eq('actor', $qb->createNamedParameter($actor)));
		}
		if ($vector !== '') {
			$qb->andWhere($qb->expr()->like('vector', $qb->createNamedParameter($this->db->escapeLikeParameter($vector) . '%')));
		}
		$res = $qb->executeQuery();
		$rows = [];
		while ($r = $res->fetch()) {
			$rows[] = [
				'id' => (int)$r['id'], 'time' => (int)$r['created_at'], 'actor' => $r['actor'] ? $this->userInfo((string)$r['actor']) : null,
				'vector' => (string)$r['vector'], 'target' => (string)$r['target'], 'mode' => (string)$r['mode'],
			];
		}
		$res->closeCursor();
		$more = count($rows) > $limit;
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from(Policy::TABLE_EVENTS)
			->where($qb->expr()->gt('created_at', $qb->createNamedParameter(time() - 86400, IQueryBuilder::PARAM_INT)));
		$last24 = (int)$qb->executeQuery()->fetchOne();
		return new DataResponse(['events' => array_slice($rows, 0, $limit), 'more' => $more, 'last24h' => $last24]);
	}

	/** Liens existants entre cloisons (lecture seule). */
	#[ApiRoute(verb: 'GET', url: '/api/v1/scan')]
	public function scan(): DataResponse {
		$items = $this->scanner->scan();
		foreach ($items as &$i) {
			$i['owner'] = $this->userInfo($i['owner']);
		}
		return new DataResponse(['items' => $items]);
	}

	#[ApiRoute(verb: 'GET', url: '/api/v1/coverage')]
	public function coverage(): DataResponse {
		$byApp = [];
		$major = \OCP\Server::get(\OCP\ServerVersion::class)->getMajorVersion();
		foreach ($this->rules->certified() as $key) {
			if ($major < $this->rules->since($key)) {
				continue;
			}
			[$class] = explode('::', $key);
			$parts = explode('\\', $class);
			$app = $parts[0] === 'OC' ? 'core' : strtolower($parts[1] ?? '?');
			$byApp[$app] = ($byApp[$app] ?? 0) + 1;
		}
		ksort($byApp);
		return new DataResponse(['routes' => $byApp, 'certifiedApps' => HealthService::CERTIFIED_APPS]);
	}
}
