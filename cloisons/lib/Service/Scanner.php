<?php

declare(strict_types=1);

namespace OCA\Cloisons\Service;

use OCP\IDBConnection;
use OCP\IGroupManager;

/**
 * Repère, dans les données existantes, tout lien entre deux cloisons :
 * créé avant l'application, ou devenu croisé après un changement de cloison
 * ou l'ajout d'un compte à un groupe utilisé comme cible.
 *
 * Lecture seule ; la conversion vers le groupe miroir est une action séparée.
 */
class Scanner {
	public function __construct(
		private IDBConnection $db,
		private ZoneService $zones,
		private Targets $targets,
		private IGroupManager $groupManager,
	) {
	}

	private function table(string $t): bool {
		return $this->db->tableExists($t);
	}

	private function groupMixed(string $gid, string $ownerUid): bool {
		return $this->groupManager->groupExists($gid) && !$this->zones->isGroupWithinZone($ownerUid, $gid);
	}

	/**
	 * Groupe d'accès (pas un groupe miroir de cloison) utilisé comme cible : sans
	 * danger tant qu'il ne contient que la cloison de l'auteur, mais il deviendra
	 * croisé dès qu'un client y sera ajouté (Nextcloud lui donnera alors accès).
	 */
	private function groupAtRisk(string $gid): bool {
		return $this->groupManager->groupExists($gid) && ZoneGroupService::zoneOfGroup($gid) === null;
	}

	private function principalUid(string $principal): ?string {
		return preg_match('#^principals/users/(.+)$#', $principal, $m) ? $m[1] : null;
	}

	private function principalGroup(string $principal): ?string {
		return preg_match('#^principals/groups/(.+)$#', $principal, $m) ? rawurldecode($m[1]) : null;
	}

	/** @return list<array{kind: string, id: string, owner: string, target: string, detail: string, convertible: bool}> */
	public function scan(): array {
		$out = [];
		$add = function (string $kind, $id, string $owner, string $target, string $detail, bool $convertible = false, string $level = 'error') use (&$out) {
			if (str_starts_with($target, ZoneService::GHOST_PREFIX)) {
				return; // cible fantôme : ne désigne aucun compte, ce n'est pas un lien
			}
			$out[] = ['kind' => $kind, 'id' => (string)$id, 'owner' => $owner, 'target' => $target, 'detail' => $detail, 'convertible' => $convertible, 'level' => $level];
		};

		// ------------------------------------------------ partages de fichiers
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'share_type', 'share_with', 'uid_owner', 'uid_initiator', 'file_target')->from('share')
			->where($qb->expr()->in('share_type', $qb->createNamedParameter([0, 1, 6, 7, 9], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('parent'));
		$res = $qb->executeQuery();
		while ($r = $res->fetch()) {
			$owner = (string)($r['uid_initiator'] ?: $r['uid_owner']);
			$with = (string)$r['share_with'];
			switch ((int)$r['share_type']) {
				case 0:
					if (!$this->zones->canSee($owner, $with) || !$this->zones->canSee((string)$r['uid_owner'], $with)) {
						$add('share-user', $r['id'], $owner, $with, (string)$r['file_target']);
					}
					break;
				case 1:
					if ($this->groupMixed($with, $owner)) {
						$add('share-group', $r['id'], $owner, $with, (string)$r['file_target'], true);
					} elseif ($this->groupAtRisk($with)) {
						$add('share-group', $r['id'], $owner, $with, (string)$r['file_target'], true, 'warning');
					}
					break;
				case 6:
				case 9:
					if ($this->targets->isLocalCloudId($with)) {
						$add('share-federated-local', $r['id'], $owner, $with, (string)$r['file_target']);
					}
					break;
				case 7:
					if (!$this->targets->isCircleWithinZone($owner, $with)) {
						$add('share-team', $r['id'], $owner, $with, (string)$r['file_target']);
					}
					break;
			}
		}
		$res->closeCursor();

		// ----------------------------------------------------------- Talk
		if ($this->table('talk_attendees')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('a.room_id', 'a.actor_type', 'a.actor_id', 'a.participant_type', 'r.token', 'r.name', 'r.type')
				->from('talk_attendees', 'a')->innerJoin('a', 'talk_rooms', 'r', 'a.room_id = r.id')
				->where($qb->expr()->in('a.actor_type', $qb->createNamedParameter(['users', 'groups', 'circles'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
			$res = $qb->executeQuery();
			$rooms = [];
			while ($r = $res->fetch()) {
				$rooms[(int)$r['room_id']]['token'] = (string)$r['token'];
				$rooms[(int)$r['room_id']]['name'] = (string)$r['name'];
				$rooms[(int)$r['room_id']][(string)$r['actor_type']][] = [(string)$r['actor_id'], (int)$r['participant_type']];
			}
			$res->closeCursor();
			foreach ($rooms as $id => $room) {
				$users = array_column($room['users'] ?? [], 0);
				$owners = array_column(array_filter($room['users'] ?? [], fn ($u) => in_array($u[1], [1, 2], true)), 0);
				$ref = $owners[0] ?? ($users[0] ?? null);
				if ($ref === null) {
					continue;
				}
				foreach ($users as $u) {
					if (!$this->zones->canSee($ref, $u)) {
						$add('talk-user', $id, $ref, $u, $room['name'] . ' (' . $room['token'] . ')');
					}
				}
				foreach (array_column($room['groups'] ?? [], 0) as $g) {
					if ($this->groupMixed($g, $ref)) {
						$add('talk-group', $id, $ref, $g, $room['name'] . ' (' . $room['token'] . ')', true);
					} elseif ($this->groupAtRisk($g)) {
						$add('talk-group', $id, $ref, $g, $room['name'] . ' (' . $room['token'] . ')', true, 'warning');
					}
				}
				foreach (array_column($room['circles'] ?? [], 0) as $c) {
					if (!$this->targets->isCircleWithinZone($ref, $c)) {
						$add('talk-team', $id, $ref, $c, $room['name'] . ' (' . $room['token'] . ')');
					}
				}
			}
		}

		// ------------------------------------------ partages d'agenda / carnet
		foreach (['calendar' => 'calendars', 'addressbook' => 'addressbooks'] as $type => $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('s.id', 's.principaluri', 'o.principaluri AS owner', 'o.displayname')
				->from('dav_shares', 's')->innerJoin('s', $table, 'o', 's.resourceid = o.id')
				->where($qb->expr()->eq('s.type', $qb->createNamedParameter($type)))
				->andWhere($qb->expr()->neq('s.access', $qb->createNamedParameter(-1))); // -1 = désinscription
			$res = $qb->executeQuery();
			while ($r = $res->fetch()) {
				$owner = $this->principalUid((string)$r['owner']);
				if ($owner === null) {
					continue;
				}
				$sharee = (string)$r['principaluri'];
				if (($u = $this->principalUid($sharee)) !== null && !$this->zones->canSee($owner, $u)) {
					$add('dav-' . $type . '-user', $r['id'], $owner, $u, (string)$r['displayname']);
				} elseif (($g = $this->principalGroup($sharee)) !== null && $this->groupMixed($g, $owner)) {
					$add('dav-' . $type . '-group', $r['id'], $owner, $g, (string)$r['displayname'], true);
				} elseif ($g !== null && $this->groupAtRisk($g)) {
					$add('dav-' . $type . '-group', $r['id'], $owner, $g, (string)$r['displayname'], true, 'warning');
				}
			}
			$res->closeCursor();
		}

		// --------------------------------------------- délégations d'agenda
		if ($this->table('dav_cal_proxy')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'owner_id', 'proxy_id')->from('dav_cal_proxy');
			$res = $qb->executeQuery();
			while ($r = $res->fetch()) {
				$o = $this->principalUid((string)$r['owner_id']);
				$p = $this->principalUid((string)$r['proxy_id']);
				if ($o !== null && $p !== null && !$this->zones->canSee($o, $p)) {
					$add('calendar-delegation', $r['id'], $o, $p, '');
				}
			}
			$res->closeCursor();
		}

		// ------------------------------------------------------- équipes
		if ($this->table('circles_member') && $this->table('circles_circle')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('m.circle_id', 'm.user_id', 'm.user_type', 'm.level', 'c.display_name', 'c.config', 'c.source')
				->from('circles_member', 'm')->innerJoin('m', 'circles_circle', 'c', 'm.circle_id = c.unique_id')
				->where($qb->expr()->in('m.user_type', $qb->createNamedParameter([1, 2], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('m.instance', $qb->createNamedParameter('')))
				->andWhere($qb->expr()->eq('m.status', $qb->createNamedParameter('Member')));
			$res = $qb->executeQuery();
			$circles = [];
			while ($r = $res->fetch()) {
				// équipes internes : cercle personnel d'un compte (1), équipes miroirs des
				// groupes et équipes techniques (système 4, backend 2048), source groupe (2)
				if (((int)$r['config'] & (1 | 4 | 2048)) !== 0 || (int)$r['source'] === 2) {
					continue;
				}
				$circles[(string)$r['circle_id']]['name'] = (string)$r['display_name'];
				$circles[(string)$r['circle_id']]['m'][] = [(string)$r['user_id'], (int)$r['user_type'], (int)$r['level']];
			}
			$res->closeCursor();
			foreach ($circles as $id => $c) {
				$owner = null;
				foreach ($c['m'] as [$u, $t, $l]) {
					if ($t === 1 && $l >= 9) {
						$owner = $u;
					}
				}
				$owner ??= ($c['m'][0][1] === 1 ? $c['m'][0][0] : null);
				if ($owner === null) {
					continue;
				}
				foreach ($c['m'] as [$u, $t]) {
					if ($t === 1 && !$this->zones->canSee($owner, $u)) {
						$add('team-user', $id, $owner, $u, $c['name']);
					} elseif ($t === 2 && $this->groupMixed($u, $owner)) {
						$add('team-group', $id, $owner, $u, $c['name']);
					} elseif ($t === 2 && $this->groupAtRisk($u)) {
						$add('team-group', $id, $owner, $u, $c['name'], false, 'warning');
					}
				}
			}
		}

		// --------------------------------------------------------- formulaires
		if ($this->table('forms_v2_shares') && $this->table('forms_v2_forms')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('s.id', 's.share_type', 's.share_with', 'f.owner_id', 'f.title')
				->from('forms_v2_shares', 's')->innerJoin('s', 'forms_v2_forms', 'f', 's.form_id = f.id')
				->where($qb->expr()->in('s.share_type', $qb->createNamedParameter([0, 1], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
			$res = $qb->executeQuery();
			while ($r = $res->fetch()) {
				$owner = (string)$r['owner_id'];
				$with = (string)$r['share_with'];
				if ((int)$r['share_type'] === 0 && !$this->zones->canSee($owner, $with)) {
					$add('form-user', $r['id'], $owner, $with, (string)$r['title']);
				} elseif ((int)$r['share_type'] === 1 && $this->groupMixed($with, $owner)) {
					$add('form-group', $r['id'], $owner, $with, (string)$r['title']);
				} elseif ((int)$r['share_type'] === 1 && $this->groupAtRisk($with)) {
					$add('form-group', $r['id'], $owner, $with, (string)$r['title'], false, 'warning');
				}
			}
			$res->closeCursor();
		}

		// ------------------------------------------------ dossiers de favoris
		if ($this->table('bookmarks_shares')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'owner', 'participant', 'type', 'folder_id')->from('bookmarks_shares')
				->where($qb->expr()->in('type', $qb->createNamedParameter([0, 1, 7], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
			$res = $qb->executeQuery();
			while ($r = $res->fetch()) {
				$owner = (string)$r['owner'];
				$with = (string)$r['participant'];
				$type = (int)$r['type'];
				if ($type === 0 && !$this->zones->canSee($owner, $with)) {
					$add('bookmarks-user', $r['id'], $owner, $with, 'dossier ' . $r['folder_id']);
				} elseif ($type === 1 && $this->groupMixed($with, $owner)) {
					$add('bookmarks-group', $r['id'], $owner, $with, 'dossier ' . $r['folder_id']);
				} elseif ($type === 1 && $this->groupAtRisk($with)) {
					$add('bookmarks-group', $r['id'], $owner, $with, 'dossier ' . $r['folder_id'], false, 'warning');
				} elseif ($type === 7 && !$this->targets->isCircleWithinZone($owner, $with)) {
					$add('bookmarks-team', $r['id'], $owner, $with, 'dossier ' . $r['folder_id']);
				}
			}
			$res->closeCursor();
		}
		return $out;
	}
}
