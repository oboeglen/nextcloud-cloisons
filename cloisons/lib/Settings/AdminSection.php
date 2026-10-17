<?php

declare(strict_types=1);

namespace OCA\Cloisons\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IURLGenerator $url,
		private IL10N $l,
	) {
	}

	public function getID(): string {
		return 'cloisons';
	}

	public function getName(): string {
		return $this->l->t('Cloisons');
	}

	public function getPriority(): int {
		return 55;
	}

	public function getIcon(): string {
		return $this->url->imagePath('cloisons', 'app-dark.svg');
	}
}
