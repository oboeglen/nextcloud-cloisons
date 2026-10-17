<?php

declare(strict_types=1);

namespace OCA\Cloisons\Service;

use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Groupe miroir d'une cloison partagée (ex. « Famille »).
 *
 * Les groupes d'accès (Office, Talk…) contiennent des comptes de plusieurs
 * cloisons : on ne peut plus partager vers eux. Chaque cloison partagée dispose
 * donc d'un vrai groupe Nextcloud contenant exactement ses membres, utilisable
 * partout comme cible (fichiers, Talk, agendas, équipes, formulaires).
 */
class ZoneGroupService {
	public const PREFIX = 'cloison-';

	public function __construct(
		private ZoneService $zones,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	public static function gid(string $zone): string {
		return self::PREFIX . $zone;
	}

	public static function zoneOfGroup(string $gid): ?string {
		return str_starts_with($gid, self::PREFIX) ? substr($gid, strlen(self::PREFIX)) : null;
	}

	/** Crée ou met à jour le groupe miroir et ses membres pour toutes les cloisons partagées. */
	public function syncAll(): array {
		$report = [];
		$members = $this->zones->namedMembers();
		foreach ($this->zones->namedZones() as $z) {
			$report[$z['id']] = $this->sync($z['id'], $z['name'], $members[$z['id']] ?? []);
		}
		// groupes miroirs orphelins (cloison supprimée)
		foreach ($this->groupManager->search(self::PREFIX) as $group) {
			$zone = self::zoneOfGroup($group->getGID());
			if ($zone !== null && !$this->zones->zoneExists($zone)) {
				$group->delete();
				$report[$zone] = ['deleted' => true];
			}
		}
		return $report;
	}

	/** @param list<string> $uids */
	public function sync(string $zone, string $name, array $uids): array {
		$gid = self::gid($zone);
		$group = $this->groupManager->get($gid) ?? $this->groupManager->createGroup($gid);
		if ($group === null) {
			return ['error' => 'création impossible'];
		}
		if ($group->getDisplayName() !== $name && method_exists($group, 'setDisplayName')) {
			$group->setDisplayName($name);
		}
		$want = array_flip($uids);
		$added = $removed = 0;
		foreach ($group->getUsers() as $user) {
			if (!isset($want[$user->getUID()])) {
				$group->removeUser($user);
				$removed++;
			}
		}
		foreach ($uids as $uid) {
			$user = $this->userManager->get($uid);
			if ($user !== null && !$group->inGroup($user)) {
				$group->addUser($user);
				$added++;
			}
		}
		return ['gid' => $gid, 'added' => $added, 'removed' => $removed];
	}

	public function deleteFor(string $zone): void {
		$this->groupManager->get(self::gid($zone))?->delete();
	}
}
