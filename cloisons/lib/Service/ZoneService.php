<?php

declare(strict_types=1);

namespace OCA\Cloisons\Service;

use OCA\Cloisons\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/**
 * Appartenance des comptes aux cloisons.
 *
 * Un compte qui n'est membre d'aucune cloison nommée est seul dans sa
 * cloison individuelle (« u:<uid> ») : l'oubli d'affectation isole par défaut.
 */
class ZoneService {
	public const INDIVIDUAL_PREFIX = 'u:';
	public const TABLE_MEMBERS = 'cloisons_members';
	public const TABLE_ZONES = 'cloisons_zones';

	/** @var array<string, string> */
	private array $zoneOf = [];
	/** @var array<string, list<string>>|null */
	private ?array $members = null;
	private ?ICache $cache = null;

	public function __construct(
		private IDBConnection $db,
		private ICacheFactory $cacheFactory,
		private IConfig $config,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private ISecureRandom $random,
	) {
	}

	private function cache(): ?ICache {
		if ($this->cache === null && $this->cacheFactory->isAvailable()) {
			$this->cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		}
		return $this->cache;
	}

	/** @return array<string, list<string>> zone => uids des cloisons nommées */
	public function namedMembers(): array {
		if ($this->members !== null) {
			return $this->members;
		}
		$cached = $this->cache()?->get('members');
		if (is_array($cached)) {
			return $this->members = $cached;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid', 'zone')->from(self::TABLE_MEMBERS);
		$res = $qb->executeQuery();
		$members = [];
		while ($row = $res->fetch()) {
			$members[(string)$row['zone']][] = (string)$row['uid'];
		}
		$res->closeCursor();
		$this->cache()?->set('members', $members, 300);
		return $this->members = $members;
	}

	public function invalidate(): void {
		$this->members = null;
		$this->zoneOf = [];
		$this->cache()?->remove('members');
	}

	public function zoneOf(string $uid): string {
		if (isset($this->zoneOf[$uid])) {
			return $this->zoneOf[$uid];
		}
		foreach ($this->namedMembers() as $zone => $uids) {
			if (in_array($uid, $uids, true)) {
				return $this->zoneOf[$uid] = $zone;
			}
		}
		return $this->zoneOf[$uid] = self::INDIVIDUAL_PREFIX . $uid;
	}

	public function canSee(string $viewer, string $target): bool {
		if ($viewer === $target) {
			return true;
		}
		return $this->zoneOf($viewer) === $this->zoneOf($target);
	}

	/** Comptes de la même cloison que $uid (lui compris). */
	public function peers(string $uid): array {
		$zone = $this->zoneOf($uid);
		if (str_starts_with($zone, self::INDIVIDUAL_PREFIX)) {
			return [$uid];
		}
		return $this->namedMembers()[$zone] ?? [$uid];
	}

	public function currentUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	public function isAdmin(string $uid): bool {
		return $this->groupManager->isAdmin($uid);
	}

	/**
	 * Un groupe n'est utilisable (comme cible ou visible) que s'il s'agit du
	 * groupe de la cloison de $viewer (« cloison-<id> ») et qu'il ne contient que
	 * ses membres. Les groupes d'accès (Office, Talk…) ne le sont jamais : un
	 * compte d'une autre cloison peut y être ajouté à tout moment, et Nextcloud
	 * lui ouvrirait alors ce qui leur a été partagé.
	 */
	public function isGroupWithinZone(string $viewer, string $gid): bool {
		$zone = \OCA\Cloisons\Service\ZoneGroupService::zoneOfGroup($gid);
		if ($zone === null || $zone !== $this->zoneOf($viewer)) {
			return false;
		}
		$group = $this->groupManager->get($gid);
		if ($group === null) {
			return false;
		}
		$peers = array_flip($this->peers($viewer));
		foreach ($group->getUsers() as $user) {
			if (!isset($peers[$user->getUID()])) {
				return false;
			}
		}
		return true;
	}

	/** Le groupe contient-il au moins un compte hors de la cloison de $viewer ? */
	public function groupHasOutsiders(string $viewer, string $gid): bool {
		$group = $this->groupManager->get($gid);
		if ($group === null) {
			return false;
		}
		$peers = array_flip($this->peers($viewer));
		foreach ($group->getUsers() as $user) {
			if (!isset($peers[$user->getUID()])) {
				return true;
			}
		}
		return false;
	}

	/** @return array{total: int, named: int, isolated: int} */
	public function countAccounts(): array {
		$total = 0;
		$named = 0;
		$this->userManager->callForAllUsers(function ($user) use (&$total, &$named) {
			$total++;
			if (!str_starts_with($this->zoneOf($user->getUID()), self::INDIVIDUAL_PREFIX)) {
				$named++;
			}
		});
		return ['total' => $total, 'named' => $named, 'isolated' => $total - $named];
	}

	/**
	 * Identifiant de compte garanti inexistant : sert à obtenir exactement la
	 * réponse qu'aurait donné Nextcloud pour un compte qui n'existe pas.
	 */
	public function ghostUid(): string {
		$ghost = $this->config->getAppValue(Application::APP_ID, 'ghost_uid', '');
		if ($ghost === '' || $this->userManager->userExists($ghost)) {
			$ghost = 'z' . $this->random->generate(23, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
			$this->config->setAppValue(Application::APP_ID, 'ghost_uid', $ghost);
		}
		return $ghost;
	}

	public function ghostEmail(): string {
		return $this->ghostUid() . '@invalid.invalid';
	}

	public const GHOST_PREFIX = 'zcl0';
	public const TABLE_GHOSTS = 'cloisons_ghosts';
	/** @var array<string, array<string, string>> zone => [fantôme => valeur] */
	private array $ghostMaps = [];

	/**
	 * Fantôme propre à une valeur cachée, stable pour la cloison de l'acteur :
	 * deux comptes cachés différents ont deux fantômes différents (comme deux
	 * comptes inexistants). La correspondance est mémorisée pour être restaurée
	 * dans toutes les réponses ultérieures faites à cette cloison.
	 */
	public function ghostFor(string $original, string $kind, ?string $actor): string {
		$zone = $actor === null ? '-' : $this->zoneOf($actor);
		$hash = substr(hash('sha256', $this->ghostUid() . '|' . $zone . '|' . $kind . '|' . $original), 0, 20);
		$ghost = match ($kind) {
			'email' => self::GHOST_PREFIX . $hash . '@invalid.invalid',
			'group' => self::GHOST_PREFIX . $hash . 'g',
			'team' => self::GHOST_PREFIX . $hash . 't',
			'cloudid' => self::GHOST_PREFIX . $hash . (($at = strrpos($original, '@')) === false ? '' : substr($original, $at)),
			default => self::GHOST_PREFIX . $hash . self::shapeSuffix($original),
		};
		if (!isset($this->ghostMaps[$zone][$ghost])) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->insert(self::TABLE_GHOSTS)->values([
					'ghost' => $qb->createNamedParameter($ghost),
					'original' => $qb->createNamedParameter(mb_substr($original, 0, 255)),
					'zone' => $qb->createNamedParameter($zone),
					'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				])->executeStatement();
			} catch (\OCP\DB\Exception $e) {
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			}
			$this->ghostMaps[$zone][$ghost] = $original;
			$this->cache()?->remove('ghosts-' . md5($zone));
		}
		return $ghost;
	}

	/**
	 * Le fantôme d'un identifiant reprend les mêmes familles de caractères que
	 * l'original (tiret, point, @, espace, apostrophe) : du code de Nextcloud
	 * trie les identifiants par leur forme (ex. aperçu de profil limité à \w+).
	 * Un fantôme de forme différente serait traité autrement qu'un compte
	 * inexistant de même forme → oracle d'existence.
	 */
	private static function shapeSuffix(string $original): string {
		$s = '';
		foreach (['-', '.', '@', ' ', "'"] as $ch) {
			if (str_contains($original, $ch)) {
				$s .= $ch . 'x';
			}
		}
		return $s;
	}

	/** @return array<string, string> fantômes connus de la cloison de $actor */
	public function ghostMap(?string $actor): array {
		$zone = $actor === null ? '-' : $this->zoneOf($actor);
		if (isset($this->ghostMaps[$zone])) {
			return $this->ghostMaps[$zone];
		}
		$cached = $this->cache()?->get('ghosts-' . md5($zone));
		if (is_array($cached)) {
			return $this->ghostMaps[$zone] = $cached;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('ghost', 'original')->from(self::TABLE_GHOSTS)->where($qb->expr()->eq('zone', $qb->createNamedParameter($zone)));
		$res = $qb->executeQuery();
		$map = [];
		while ($row = $res->fetch()) {
			$map[(string)$row['ghost']] = (string)$row['original'];
		}
		$res->closeCursor();
		$this->cache()?->set('ghosts-' . md5($zone), $map, 600);
		return $this->ghostMaps[$zone] = $map;
	}

	/** Remplace dans $text tout fantôme connu de la cloison de $actor par sa valeur d'origine. */
	public function restoreGhosts(string $text, ?string $actor): string {
		if (!str_contains($text, self::GHOST_PREFIX)) {
			return $text;
		}
		$map = $this->ghostMap($actor);
		return $map === [] ? $text : strtr($text, $map);
	}

	// ---------------------------------------------------------------- gestion

	/** @return list<array{id: string, name: string}> */
	public function namedZones(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('zone_id', 'display_name')->from(self::TABLE_ZONES)->orderBy('display_name');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = ['id' => (string)$row['zone_id'], 'name' => (string)$row['display_name']];
		}
		$res->closeCursor();
		return $out;
	}

	public function zoneExists(string $zone): bool {
		foreach ($this->namedZones() as $z) {
			if ($z['id'] === $zone) {
				return true;
			}
		}
		return false;
	}

	public function createZone(string $id, string $name): void {
		if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $id)) {
			throw new \InvalidArgumentException('Identifiant de cloison invalide');
		}
		if ($this->zoneExists($id)) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_ZONES)->values([
			'zone_id' => $qb->createNamedParameter($id),
			'display_name' => $qb->createNamedParameter($name),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	public function renameZone(string $id, string $name): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_ZONES)->set('display_name', $qb->createNamedParameter($name))
			->where($qb->expr()->eq('zone_id', $qb->createNamedParameter($id)))->executeStatement();
	}

	public function deleteZone(string $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_MEMBERS)->where($qb->expr()->eq('zone', $qb->createNamedParameter($id)))->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_ZONES)->where($qb->expr()->eq('zone_id', $qb->createNamedParameter($id)))->executeStatement();
		$this->invalidate();
	}

	/** Place $uid dans la cloison nommée $zone, ou dans sa cloison individuelle si $zone est null. */
	public function assign(string $uid, ?string $zone): void {
		if ($zone !== null && !$this->zoneExists($zone)) {
			throw new \InvalidArgumentException('Cloison inconnue : ' . $zone);
		}
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE_MEMBERS)->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))->executeStatement();
			if ($zone !== null) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert(self::TABLE_MEMBERS)->values([
					'uid' => $qb->createNamedParameter($uid),
					'zone' => $qb->createNamedParameter($zone),
					'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				])->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->invalidate();
	}
}
