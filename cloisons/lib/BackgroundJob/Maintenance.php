<?php

declare(strict_types=1);

namespace OCA\Cloisons\BackgroundJob;

use OCA\Cloisons\Service\Policy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Purge quotidienne du journal (30 jours, 20 000 entrées au plus). */
class Maintenance extends TimedJob {
	public const RETENTION_DAYS = 30;
	public const MAX_EVENTS = 20000;

	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private \OCA\Cloisons\Service\ZoneGroupService $groups,
		private \OCA\Cloisons\Service\Scanner $scanner,
		private \OCA\Cloisons\Service\Policy $policy,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600); // une fois par jour
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->purge();
	}

	/** Purge du journal propre : 30 jours au plus, 20 000 entrées au plus. */
	public function purge(): int {
		$deleted = 0;
		$qb = $this->db->getQueryBuilder();
		$deleted += $qb->delete(Policy::TABLE_EVENTS)
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter(time() - self::RETENTION_DAYS * 86400, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(Policy::TABLE_EVENTS)->orderBy('id', 'DESC')->setFirstResult(self::MAX_EVENTS)->setMaxResults(1);
		$cut = $qb->executeQuery()->fetchOne();
		if ($cut !== false) {
			$qb = $this->db->getQueryBuilder();
			$deleted += $qb->delete(Policy::TABLE_EVENTS)->where($qb->expr()->lte('id', $qb->createNamedParameter((int)$cut, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		// correspondances de fantômes inutilisées depuis plus d'un an
		$qb = $this->db->getQueryBuilder();
		$qb->delete(\OCA\Cloisons\Service\ZoneService::TABLE_GHOSTS)
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter(time() - 365 * 86400, IQueryBuilder::PARAM_INT)))->executeStatement();
		$this->maintain();
		$this->scanCrossZone();
		return $deleted;
	}

	/**
	 * Vérifie chaque jour qu'aucun lien croisé n'est apparu dans les données
	 * (changement de cloison, ajout d'un compte à un groupe utilisé comme cible…)
	 * et le signale une fois au journal pour qu'il soit traité (occ cloisons:scan).
	 */
	private function scanCrossZone(): void {
		try {
			$cross = array_filter($this->scanner->scan(), fn ($i) => ($i['level'] ?? '') === 'error');
		} catch (\Throwable $e) {
			$this->policy->graveOnce('scan', 'Cloisons : analyse des liens entre cloisons impossible', $e);
			return;
		}
		if ($cross !== []) {
			$this->policy->graveOnce('scan-cross',
				'Cloisons : ' . count($cross) . ' lien(s) existant(s) entre cloisons détecté(s) — voir occ cloisons:scan');
		}
	}

	private function maintain(): void {
		$this->groups->syncAll();
		// notifications laissées par des applications à des comptes fantômes
		if ($this->db->tableExists('notifications')) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('notifications')->where($qb->expr()->like('user', $qb->createNamedParameter(\OCA\Cloisons\Service\ZoneService::GHOST_PREFIX . '%')))->executeStatement();
		}
	}
}
