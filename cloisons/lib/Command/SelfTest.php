<?php

declare(strict_types=1);

namespace OCA\Cloisons\Command;

use OCA\Cloisons\Service\HealthService;
use OCA\Cloisons\Service\ZoneService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ cloisons:selftest — contrôles sans aucune écriture de données :
 * santé, compte fantôme inexistant, appartenance cohérente.
 * À lancer après chaque mise à jour de Nextcloud ou d'une application.
 */
class SelfTest extends Command {
	public function __construct(
		private HealthService $health,
		private ZoneService $zones,
		private \OCP\IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('cloisons:selftest')->setDescription('Autotest du cloisonnement (lecture seule)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$ok = true;
		$report = $this->health->report();
		foreach ($report['checks'] as $c) {
			$output->writeln(sprintf('[%s] %s — %s', strtoupper($c['level']), $c['label'], $c['detail']));
		}
		$ok = $ok && $report['ok'];

		$ghost = $this->zones->ghostUid();
		$ghostOk = !$this->userManager->userExists($ghost);
		$output->writeln(sprintf('[%s] Compte fantôme inexistant', $ghostOk ? 'OK' : 'ERROR'));
		$ok = $ok && $ghostOk;

		$orphans = [];
		foreach ($this->zones->namedMembers() as $zone => $uids) {
			foreach ($uids as $uid) {
				if (!$this->userManager->userExists($uid)) {
					$orphans[] = "$uid ($zone)";
				}
			}
		}
		$output->writeln(sprintf('[%s] Membres de cloisons existants%s', $orphans ? 'WARNING' : 'OK', $orphans ? ' — orphelins : ' . implode(', ', $orphans) : ''));

		$output->writeln($ok ? 'Autotest réussi' : 'Autotest en échec');
		return $ok ? 0 : 1;
	}
}
