<?php

declare(strict_types=1);

namespace OCA\Cloisons\Middleware;

use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\Rules;
use OCA\Cloisons\Service\ZoneService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Middleware global : s'applique à tous les contrôleurs AppFramework (cœur et
 * applications). Pour chaque route certifiée, il
 *   1. remplace avant exécution toute référence à un compte hors cloison par un
 *      compte fantôme inexistant : la réponse est exactement celle que donne
 *      Nextcloud pour un compte qui n'existe pas (aucun oracle d'existence) ;
 *   2. filtre après exécution les listes et résultats de recherche.
 */
class IsolationMiddleware extends Middleware {
	/** Espace de noms → application : restriction de groupe appliquée aussi à l'API « page publique ». */
	private const RESTRICTED_PUBLIC_APPS = ['OCA\Bookmarks\\' => 'bookmarks'];

	/** @var array<string, string> fantôme => valeur d'origine, pour restaurer les échos */
	private array $echo = [];
	/** @var list<callable> */
	private array $filters = [];
	private ?string $actor = null;

	public function __construct(
		private IRequest $request,
		private ZoneService $zones,
		private Policy $policy,
		private Rules $rules,
		private PublicGuard $publicGuard,
		private \OCA\Cloisons\Contacts\ContactsGuard $contactsGuard,
		private LoggerInterface $logger,
	) {
	}

	public function beforeController($controller, $methodName): void {
		$this->echo = [];
		$this->filters = [];
		$rule = $this->rules->forController(get_class($controller), $methodName);
		$actorNow = $this->zones->currentUid();
		if ($actorNow !== null) {
			$this->contactsGuard->install();
		}
		// Applications réservées à des groupes mais exposant une API « page publique »
		// (Nextcloud n'y vérifie pas l'accès à l'application) : refusées à un compte
		// connecté non autorisé, comme une application non activée pour lui.
		if ($actorNow !== null) {
			// accès par lien public (jeton) exempté : il relève de la règle des liens publics
			$byToken = $this->request->getParam('token') !== null
				|| str_starts_with(strtolower((string)$this->request->getHeader('Authorization')), 'bearer ');
			foreach (self::RESTRICTED_PUBLIC_APPS as $prefix => $app) {
				if (!$byToken && str_starts_with(get_class($controller), $prefix)
					&& !\OCP\Server::get(\OCP\App\IAppManager::class)->isEnabledForUser($app)) {
					$this->policy->block('app-restricted:' . $app, $actorNow, null);
					if ($this->policy->enforcing()) {
						$this->notFound($controller);
					}
				}
			}
		}
		// Liens publics d'une autre cloison : refusés à un compte connecté
		if ($actorNow !== null) {
			$guardCtx = new RuleContext($this->request, $this->zones, $this->policy, $actorNow, 'public-link');
			$guard = $this->publicGuard->check($controller, $this->request, $actorNow, $guardCtx);
			if ($guard !== [] && $this->policy->enforcing() && $controller instanceof \OCP\AppFramework\PublicShareController) {
				// le jeton a déjà été validé par Nextcloud : même réponse qu'un partage inexistant
				throw new \OCP\Files\NotFoundException();
			}
			if ($guard !== [] && $this->policy->enforcing()) {
				foreach ($guard as $name => $ghost) {
					$this->echo[$ghost] = (string)$this->request->getParam($name);
				}
				$this->override($guard);
			}
		}
		if ($rule === null) {
			return;
		}
		$actor = $this->zones->currentUid();
		if ($actor === null && empty($rule['anonymous'])) {
			return;
		}
		if ($actor !== null && !empty($rule['adminExempt']) && $this->zones->isAdmin($actor)) {
			return;
		}
		$this->actor = $actor;
		$ctx = new RuleContext($this->request, $this->zones, $this->policy, $actor, $rule['vector']);

		// 1. paramètres
		$overrides = [];
		foreach ($rule['params'] ?? [] as $name => $kind) {
			$value = $this->request->getParam($name);
			if ($value === null || $value === '') {
				continue;
			}
			$new = $ctx->sanitizeParam($kind, $name, $value);
			if ($new !== $value) {
				$overrides[$name] = $new;
			}
		}
		if (isset($rule['before'])) {
			$overrides = array_merge($overrides, ($rule['before'])($ctx) ?? []);
		}
		if ($overrides !== [] && $this->policy->enforcing()) {
			$this->override($overrides);
			$this->echo = array_merge($this->echo, $ctx->echoMap());
		}

		// 2. filtres de sortie
		if (isset($rule['after'])) {
			$this->filters[] = fn (Response $r) => ($rule['after'])($ctx, $r);
		}
		if ($ctx->denied()) {
			// Refus non masquable par un fantôme : réponse « introuvable » générique
			$this->notFound($controller);
		}
	}

	/**
	 * Réponse « introuvable » adaptée au contrôleur : exception OCS pour un
	 * contrôleur OCS, réponse 404 directe sinon (une exception OCS levée hors
	 * OCS donnerait une erreur 500 journalisée).
	 *
	 * @return never
	 */
	private function notFound(object $controller): void {
		if ($controller instanceof \OCP\AppFramework\OCSController) {
			throw new OCSNotFoundException();
		}
		throw new ShortCircuit(new DataResponse([], Http::STATUS_NOT_FOUND));
	}

	private function override(array $values): void {
		if (!method_exists($this->request, 'setUrlParameters')) {
			// Mécanisme indisponible (version de Nextcloud non certifiée) : refuser plutôt que laisser fuir
			$this->policy->graveOnce('substitution', 'Cloisons : substitution de paramètres impossible (version de Nextcloud non certifiée), requêtes concernées refusées');
			throw new OCSNotFoundException();
		}
		$url = (array)($this->request->urlParams ?? []);
		$this->request->setUrlParameters(array_merge($url, $values));
	}

	public function afterController($controller, $methodName, Response $response): Response {
		\OCA\Cloisons\Listener\AnonymousAdminListener::forceReset();
		$this->restorePersistentGhosts($response);
		if ($this->filters === [] && $this->echo === []) {
			return $response;
		}
		if ($this->policy->enforcing()) {
			if (!ResponseData::has($response) && !($response instanceof TemplateResponse)) {
				return $response;
			}
			foreach ($this->filters as $f) {
				$response = $f($response) ?? $response;
			}
		} else {
			foreach ($this->filters as $f) {
				// mode audit : évaluer pour journaliser, sans modifier la réponse
				$f(clone $response);
			}
		}
		if ($this->echo !== []) {
			$response = $this->restoreEcho($response);
		}
		return $response;
	}

	/**
	 * Une erreur levée par l'application (« compte introuvable »…) doit citer la
	 * valeur saisie, pas le fantôme : on relance la même exception, texte restauré.
	 */
	public function afterException($controller, $methodName, \Exception $exception): Response {
		\OCA\Cloisons\Listener\AnonymousAdminListener::forceReset();
		if ($exception instanceof ShortCircuit) {
			$response = $exception->response;
			if ($controller instanceof \OCP\AppFramework\OCSController) {
				$format = $this->request->getFormat();
				return ($format !== null && $controller->isResponderRegistered($format))
					? $controller->buildResponse($response, $format)
					: $controller->buildResponse($response);
			}
			return $response;
		}
		$message = $exception->getMessage();
		$restored = $this->zones->restoreGhosts($this->echo === [] ? $message : strtr($message, $this->echo), $this->zones->currentUid());
		if ($restored === $message) {
			throw $exception;
		}
		$class = get_class($exception);
		try {
			$new = new $class($restored, $exception->getCode(), $exception->getPrevious());
		} catch (\Throwable) {
			try {
				$new = new $class($restored);
			} catch (\Throwable) {
				throw $exception;
			}
		}
		throw $new;
	}

	/**
	 * Une valeur fantôme mémorisée par une application (participant invité par
	 * e-mail, signataire…) est toujours rendue sous sa forme d'origine à la
	 * cloison qui l'a saisie.
	 */
	private function restorePersistentGhosts(Response $response): void {
		$actor = $this->zones->currentUid();
		if ($actor === null || !ResponseData::has($response)) {
			return;
		}
		$map = $this->zones->ghostMap($actor);
		if ($map === []) {
			return;
		}
		$data = ResponseData::get($response);
		$json = json_encode($data);
		if ($json === false || !str_contains($json, ZoneService::GHOST_PREFIX)) {
			return;
		}
		$walk = function ($v) use (&$walk, $map) {
			if (is_string($v)) {
				return str_contains($v, ZoneService::GHOST_PREFIX) ? strtr($v, $map) : $v;
			}
			if (is_array($v)) {
				$out = [];
				foreach ($v as $k => $x) {
					$out[is_string($k) ? strtr($k, $map) : $k] = $walk($x);
				}
				return $out;
			}
			return $v;
		};
		ResponseData::set($response, $walk($data));
	}

	private function restoreEcho(Response $response): Response {
		$map = $this->echo;
		$walk = function ($v) use (&$walk, $map) {
			if (is_string($v)) {
				return strtr($v, $map);
			}
			if (is_array($v)) {
				$out = [];
				foreach ($v as $k => $x) {
					$out[is_string($k) ? strtr($k, $map) : $k] = $walk($x);
				}
				return $out;
			}
			return $v;
		};
		if (ResponseData::has($response)) {
			ResponseData::set($response, $walk(ResponseData::get($response)));
		} elseif ($response instanceof TemplateResponse) {
			$response->setParams($walk($response->getParams()));
		}
		return $response;
	}
}
