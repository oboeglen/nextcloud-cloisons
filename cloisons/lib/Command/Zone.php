<?php

declare(strict_types=1);

namespace OCA\Cloisons\Command;

use OCA\Cloisons\Service\ZoneService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** occ cloisons:zone — gestion des cloisons nommées et de leurs membres. */
class Zone extends Command {
	public function __construct(
		private ZoneService $zones,
		private IUserManager $userManager,
		private \OCA\Cloisons\Service\ZoneGroupService $groups,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('cloisons:zone')
			->setDescription('Gère les cloisons nommées : list, create, rename, delete, add, remove, show')
			->addArgument('action', InputArgument::REQUIRED, 'list|create|rename|delete|add|remove|show')
			->addArgument('zone', InputArgument::OPTIONAL, 'identifiant de la cloison (ex. famille)')
			->addArgument('value', InputArgument::OPTIONAL, 'compte (add/remove/show) ou nom affiché (create/rename)')
			->addOption('output', null, InputOption::VALUE_OPTIONAL, 'plain|json', 'plain');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$action = (string)$input->getArgument('action');
		$zone = $input->getArgument('zone');
		$value = $input->getArgument('value');
		try {
			switch ($action) {
				case 'list':
					$rows = [];
					$members = $this->zones->namedMembers();
					foreach ($this->zones->namedZones() as $z) {
						$rows[] = ['id' => $z['id'], 'name' => $z['name'], 'members' => $members[$z['id']] ?? []];
					}
					if ($input->getOption('output') === 'json') {
						$output->writeln(json_encode($rows));
					} else {
						foreach ($rows as $r) {
							$output->writeln(sprintf('%s (%s) : %s', $r['id'], $r['name'], implode(', ', $r['members']) ?: '—'));
						}
						$output->writeln('Tout autre compte est seul dans sa cloison individuelle.');
					}
					return 0;
				case 'create':
					$this->zones->createZone((string)$zone, (string)($value ?? $zone));
					break;
				case 'rename':
					$this->zones->renameZone((string)$zone, (string)$value);
					break;
				case 'delete':
					$this->zones->deleteZone((string)$zone);
					break;
				case 'add':
					if (!$this->userManager->userExists((string)$value)) {
						$output->writeln('<error>Compte inconnu</error>');
						return 1;
					}
					$this->zones->assign((string)$value, (string)$zone);
					break;
				case 'remove':
					$this->zones->assign((string)$value, null);
					break;
				case 'show':
					$uid = (string)($value ?? $zone);
					$output->writeln($uid . ' → ' . $this->zones->zoneOf($uid));
					return 0;
				default:
					$output->writeln('<error>Action inconnue</error>');
					return 1;
			}
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$this->groups->syncAll();
		$output->writeln('OK');
		return 0;
	}
}
