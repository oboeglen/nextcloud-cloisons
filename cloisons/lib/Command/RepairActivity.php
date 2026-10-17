<?php

declare(strict_types=1);

namespace OCA\Cloisons\Command;

use OCA\Cloisons\Service\ZoneService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ cloisons:repair-activity — activités déjà enregistrées où un administrateur
 * d'une autre cloison est nommé (groupe, mot de passe, adresse) : réécrites dans
 * la forme native anonyme (« Un administrateur… »).
 */
class RepairActivity extends Command {
	public function __construct(
		private IDBConnection $db,
		private ZoneService $zones,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('cloisons:repair-activity')
			->setDescription('Anonymise les administrateurs d\'une autre cloison dans les activités existantes')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'afficher sans modifier');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->db->tableExists('activity')) {
			$output->writeln('Application Activité absente.');
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('activity_id', 'user', 'affecteduser', 'subject', 'subjectparams')->from('activity')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('settings')))
			->andWhere($qb->expr()->in('subject', $qb->createNamedParameter(
				['group_added', 'group_removed', 'password_changed_by', 'email_changed_by'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		$res = $qb->executeQuery();
		$n = 0;
		while ($r = $res->fetch()) {
			$author = (string)$r['user'];
			$affected = (string)$r['affecteduser'];
			$params = json_decode((string)$r['subjectparams'], true);
			$actor = is_array($params) ? (string)($params['actor'] ?? ($params[0] ?? $author)) : $author;
			if ($actor === '' || $actor === $affected || $this->zones->canSee($affected, $actor)) {
				continue;
			}
			$subject = (string)$r['subject'];
			$newSubject = $subject;
			$newParams = $params;
			if ($subject === 'group_added' || $subject === 'group_removed') {
				unset($newParams['actor']);
			} elseif ($subject === 'password_changed_by') {
				$newSubject = 'password_changed';
				$newParams = [];
			} elseif ($subject === 'email_changed_by') {
				$newSubject = 'email_changed';
				$newParams = [];
			}
			$n++;
			$output->writeln(sprintf('#%s %s → %s (%s)', $r['activity_id'], $subject, $newSubject, $affected));
			if (!$input->getOption('dry-run')) {
				$u = $this->db->getQueryBuilder();
				$u->update('activity')
					->set('user', $u->createNamedParameter(''))
					->set('subject', $u->createNamedParameter($newSubject))
					->set('subjectparams', $u->createNamedParameter(json_encode($newParams)))
					->where($u->expr()->eq('activity_id', $u->createNamedParameter((int)$r['activity_id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
		}
		$res->closeCursor();
		$output->writeln($n . ' activité(s) ' . ($input->getOption('dry-run') ? 'à corriger' : 'corrigée(s)'));
		return 0;
	}
}
