<?php

declare(strict_types=1);

namespace OCA\Cloisons\Command;

use OCA\Cloisons\Service\Scanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ cloisons:scan — liens existants entre cloisons (lecture seule).
 * Code retour 1 si un lien croisé existe, 2 s'il n'y a que des groupes d'accès à risque.
 */
class Scan extends Command {
	private const LABELS = [
		'share-user' => 'Partage de fichier vers un compte',
		'share-group' => 'Partage de fichier vers un groupe',
		'share-federated-local' => 'Partage fédéré vers ce serveur',
		'share-team' => 'Partage de fichier vers une équipe',
		'talk-user' => 'Participant Talk',
		'talk-group' => 'Groupe ajouté à une conversation Talk',
		'talk-team' => 'Équipe ajoutée à une conversation Talk',
		'dav-calendar-user' => 'Agenda partagé à un compte',
		'dav-calendar-group' => 'Agenda partagé à un groupe',
		'dav-addressbook-user' => 'Carnet partagé à un compte',
		'dav-addressbook-group' => 'Carnet partagé à un groupe',
		'calendar-delegation' => 'Délégation d\'agenda',
		'team-user' => 'Membre d\'équipe',
		'team-group' => 'Groupe membre d\'une équipe',
		'form-user' => 'Formulaire partagé à un compte',
		'form-group' => 'Formulaire partagé à un groupe',
	];

	public function __construct(
		private Scanner $scanner,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('cloisons:scan')
			->setDescription('Liste les liens existants entre cloisons (lecture seule)')
			->addOption('output', null, InputOption::VALUE_OPTIONAL, 'plain|json', 'plain');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$items = $this->scanner->scan();
		if ($input->getOption('output') === 'json') {
			$output->writeln(json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		} else {
			foreach ($items as $i) {
				$output->writeln(sprintf('[%s] %s #%s : %s → %s %s', $i['level'] === 'error' ? 'CROISÉ' : 'À RISQUE',
					self::LABELS[$i['kind']] ?? $i['kind'], $i['id'], $i['owner'], $i['target'], $i['detail'] !== '' ? '(' . $i['detail'] . ')' : ''));
			}
			$output->writeln(count($items) === 0 ? 'Aucun lien entre cloisons.' : count($items) . ' élément(s).');
		}
		$levels = array_column($items, 'level');
		return in_array('error', $levels, true) ? 1 : ($levels !== [] ? 2 : 0);
	}
}
