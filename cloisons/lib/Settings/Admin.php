<?php

declare(strict_types=1);

namespace OCA\Cloisons\Settings;

use OCA\Cloisons\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'cloisons-admin');
		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return 'cloisons';
	}

	public function getPriority(): int {
		return 10;
	}
}
