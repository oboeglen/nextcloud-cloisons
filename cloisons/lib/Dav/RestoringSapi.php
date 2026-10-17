<?php

declare(strict_types=1);

namespace OCA\Cloisons\Dav;

use Sabre\HTTP\ResponseInterface;
use Sabre\HTTP\Sapi;

/**
 * Point d'émission de toutes les réponses WebDAV, y compris les erreurs que
 * Sabre construit hors du cycle des greffons : les fantômes y sont remplacés
 * par la valeur saisie avant l'envoi.
 */
class RestoringSapi extends Sapi {
	private static ?IsolationPlugin $plugin = null;

	public static function attach(IsolationPlugin $plugin): void {
		self::$plugin = $plugin;
	}

	public static function sendResponse(ResponseInterface $response): void {
		self::$plugin?->finalize($response);
		parent::sendResponse($response);
	}
}
