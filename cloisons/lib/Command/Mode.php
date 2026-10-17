<?php

declare(strict_types=1);

namespace OCA\Cloisons\Command;

use OCA\Cloisons\Service\Policy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** occ cloisons:mode [audit|enforce] */
class Mode extends Command {
	public function __construct(
		private Policy $policy,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('cloisons:mode')
			->setDescription('Affiche ou change le mode : audit (journalise sans bloquer) ou enforce (applique)')
			->addArgument('mode', InputArgument::OPTIONAL, 'audit|enforce');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$mode = $input->getArgument('mode');
		if ($mode !== null) {
			try {
				$this->policy->setMode((string)$mode);
			} catch (\InvalidArgumentException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
		}
		$output->writeln($this->policy->mode());
		return 0;
	}
}
