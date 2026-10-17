<?php

declare(strict_types=1);

namespace OCA\Cloisons\Command;

use OCA\Cloisons\Service\HealthService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** occ cloisons:status — état de santé (code retour 1 si un contrôle échoue). */
class Status extends Command {
	public function __construct(
		private HealthService $health,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('cloisons:status')
			->setDescription('État de santé du cloisonnement')
			->addOption('output', null, InputOption::VALUE_OPTIONAL, 'plain|json', 'plain');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$report = $this->health->report();
		if ($input->getOption('output') === 'json') {
			$output->writeln(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		} else {
			$output->writeln('Mode : ' . $report['mode']);
			foreach ($report['checks'] as $c) {
				$output->writeln(sprintf('[%s] %s — %s', strtoupper($c['level']), $c['label'], $c['detail']));
			}
		}
		return $report['ok'] ? 0 : 1;
	}
}
