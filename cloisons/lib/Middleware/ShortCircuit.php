<?php

declare(strict_types=1);

namespace OCA\Cloisons\Middleware;

use OCP\AppFramework\Http\DataResponse;

/**
 * Réponse rendue directement, sans exécuter le contrôleur : identique à ce que
 * l'application aurait répondu pour une cible inexistante, mais sans les erreurs
 * qu'elle écrirait au journal en la cherchant.
 */
class ShortCircuit extends \Exception {
	public function __construct(
		public readonly DataResponse $response,
	) {
		parent::__construct('cloisons-short-circuit');
	}
}
