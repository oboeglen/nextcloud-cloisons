<?php

declare(strict_types=1);

namespace OCA\Cloisons\Migration;

use OCA\Cloisons\AppInfo\Application;
use OCA\Cloisons\Service\ZoneService;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Valeurs par défaut : mode audit (rien n'est bloqué) et compte fantôme. */
class InstallDefaults implements IRepairStep {
	public function __construct(
		private IConfig $config,
		private ZoneService $zones,
	) {
	}

	public function getName(): string {
		return 'Cloisons : valeurs par défaut';
	}

	public function run(IOutput $output): void {
		if ($this->config->getAppValue(Application::APP_ID, 'mode', '') === '') {
			$this->config->setAppValue(Application::APP_ID, 'mode', 'audit');
		}
		$this->zones->ghostUid();
	}
}
