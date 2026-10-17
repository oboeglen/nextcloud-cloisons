<?php

declare(strict_types=1);

namespace OCA\Cloisons\Service;

use OCA\Cloisons\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Mode de fonctionnement et journal des décisions.
 *
 *  - audit   : tout est évalué et journalisé, rien n'est bloqué ni filtré ;
 *  - enforce : les décisions sont appliquées.
 */
class Policy {
	public const MODE_AUDIT = 'audit';
	public const MODE_ENFORCE = 'enforce';
	public const TABLE_EVENTS = 'cloisons_events';

	private ?string $mode = null;
	/** @var array<string, true> */
	private array $loggedThisRequest = [];

	public function __construct(
		private IConfig $config,
		private IDBConnection $db,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Seule écriture dans le journal de Nextcloud : une panne réelle, au plus une
	 * fois par heure (les décisions vont dans le journal propre de l'application).
	 */
	public function graveOnce(string $key, string $message, ?\Throwable $e = null): void {
		$cache = $this->cacheFactory->isAvailable() ? $this->cacheFactory->createDistributed(Application::APP_ID . '-grave') : null;
		if ($cache !== null && $cache->get($key) !== null) {
			return;
		}
		$cache?->set($key, 1, 3600);
		$this->logger->error($message, array_filter(['exception' => $e, 'app' => Application::APP_ID]));
	}

	public function mode(): string {
		if ($this->mode === null) {
			$m = $this->config->getAppValue(Application::APP_ID, 'mode', self::MODE_AUDIT);
			$this->mode = in_array($m, [self::MODE_AUDIT, self::MODE_ENFORCE], true) ? $m : self::MODE_AUDIT;
		}
		return $this->mode;
	}

	public function setMode(string $mode): void {
		if (!in_array($mode, [self::MODE_AUDIT, self::MODE_ENFORCE], true)) {
			throw new \InvalidArgumentException('Mode inconnu : ' . $mode);
		}
		$this->config->setAppValue(Application::APP_ID, 'mode', $mode);
		$this->mode = $mode;
	}

	public function enforcing(): bool {
		return $this->mode() === self::MODE_ENFORCE;
	}

	/**
	 * Journalise une décision (au plus une fois par heure pour un même triplet).
	 * Retourne true si la décision doit être appliquée (mode enforce).
	 */
	public function block(string $vector, ?string $actor, ?string $target = null): bool {
		$key = $vector . '|' . ($actor ?? '') . '|' . ($target ?? '');
		if (!isset($this->loggedThisRequest[$key])) {
			$this->loggedThisRequest[$key] = true;
			$cache = $this->cacheFactory->isAvailable() ? $this->cacheFactory->createDistributed(Application::APP_ID . '-ev') : null;
			$ck = md5($key);
			if ($cache === null || $cache->get($ck) === null) {
				$cache?->set($ck, 1, 3600);
				try {
					$qb = $this->db->getQueryBuilder();
					$qb->insert(self::TABLE_EVENTS)->values([
						'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
						'actor' => $qb->createNamedParameter(mb_substr($actor ?? '', 0, 64)),
						'vector' => $qb->createNamedParameter(mb_substr($vector, 0, 128)),
						'target' => $qb->createNamedParameter(mb_substr($target ?? '', 0, 255)),
						'mode' => $qb->createNamedParameter($this->mode()),
					])->executeStatement();
				} catch (\Throwable $e) {
					$this->graveOnce('journal', 'Cloisons : journal interne indisponible', $e);
				}
			}
		}
		return $this->enforcing();
	}
}
