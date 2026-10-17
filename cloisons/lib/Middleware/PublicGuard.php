<?php

declare(strict_types=1);

namespace OCA\Cloisons\Middleware;

use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\ZoneService;
use OCP\IRequest;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * Liens publics d'une autre cloison ouverts par un compte connecté.
 *
 * Sur une instance dédiée, ce compte serait un invité anonyme. Pour ne jamais
 * exposer son identité de compte, l'accès est refusé tant qu'il est connecté
 * (réponse identique à un lien inexistant) ; il reste possible en navigation
 * privée. Exception : un lien de fichier en lecture seule reste consultable et
 * téléchargeable (aucune identité n'y est exposée), mais pas dans un éditeur.
 */
class PublicGuard {
	/** Contrôleurs de consultation simple d'un lien de fichier (lecture seule). */
	private const READ_ONLY_VIEWERS = [
		'OCA\Files_Sharing\Controller\ShareController',
		'OCA\Files_Sharing\Controller\PublicPreviewController',
		'OCA\Files_Sharing\Controller\ShareInfoController',
		'OCA\Files_PDFViewer\Controller\DisplayController',
	];

	/** Paramètres porteurs d'un jeton public, par famille d'application. */
	private const PARAMS = ['token', 'shareToken', 'sharingToken', 't', 'hash', 'shareHash', 'appointmentConfigToken'];

	/** @var array<string, string|false> */
	private array $ownerCache = [];

	public function __construct(
		private ZoneService $zones,
		private Policy $policy,
	) {
	}

	/**
	 * @return array<string, string> paramètres à remplacer (jeton → jeton inexistant)
	 */
	public function ghostFileToken(string $original): string {
		return $this->ghostToken($original, 'files');
	}

	public function ghostCalendarToken(string $original): string {
		return $this->ghostToken($original, 'calendar');
	}

	public function check(object $controller, IRequest $request, string $actor, RuleContext $ctx): array {
		$class = get_class($controller);
		$family = match (true) {
			str_starts_with($class, 'OCA\Talk\\') => 'talk',
			str_starts_with($class, 'OCA\Forms\\') => 'forms',
			str_starts_with($class, 'OCA\Calendar\\') => 'calendar',
			str_starts_with($class, 'OCA\Files_Sharing\\'),
			str_starts_with($class, 'OCA\Text\\'),
			str_starts_with($class, 'OCA\Onlyoffice\\'),
			str_starts_with($class, 'OCA\Whiteboard\\'),
			str_starts_with($class, 'OCA\Collectives\\'),
			str_starts_with($class, 'OCA\Files_PDFViewer\\'),
			str_starts_with($class, 'OCA\Viewer\\'),
			$class === 'OC\Core\Controller\ReferenceApiController' => 'files',
			default => null,
		};
		if ($family === null) {
			return [];
		}
		$out = [];
		foreach (self::PARAMS as $name) {
			$value = $request->getParam($name);
			if (!is_string($value) || $value === '' || strlen($value) > 64) {
				continue;
			}
			$blocked = match ($family) {
				'talk' => $name === 'token' && $this->talkBlocked($ctx, $value),
				'forms' => in_array($name, ['hash', 'shareHash'], true) && $this->formBlocked($actor, $value),
				'calendar' => in_array($name, ['token', 'appointmentConfigToken'], true) && $this->calendarBlocked($actor, $value),
				'files' => in_array($name, ['token', 'shareToken', 'sharingToken', 't'], true)
					&& $this->fileShareBlocked($actor, $value, in_array($class, self::READ_ONLY_VIEWERS, true)
						&& !str_starts_with($class, 'OCA\Collectives\\')),
			};
			if ($blocked) {
				$this->policy->block('public-link:' . $family, $actor, $value);
				$out[$name] = $this->ghostToken($value, $family);
			}
		}
		return $out;
	}

	/** Jeton de même forme que l'original mais qui n'existe pas. */
	private function ghostToken(string $original, string $family): string {
		$h = hash('sha256', $this->zones->ghostUid() . '|' . $family . '|' . $original);
		return match ($family) {
			'talk' => substr('zz' . preg_replace('/[^a-z0-9]/', '', $h), 0, max(8, min(30, strlen($original)))),
			default => substr(strtr(base64_encode(hex2bin($h)), ['+' => 'a', '/' => 'b', '=' => '']), 0, max(15, strlen($original))),
		};
	}

	private function crossZone(string $actor, ?string $owner): bool {
		return $owner !== null && $owner !== '' && !$this->zones->canSee($actor, $owner);
	}

	public function fileShareBlocked(string $actor, string $token, bool $readOnlyViewer): bool {
		try {
			$share = Server::get(IShareManager::class)->getShareByToken($token);
		} catch (\Throwable) {
			return false; // jeton inconnu : réponse native
		}
		$cross = $this->crossZone($actor, $share->getShareOwner()) || $this->crossZone($actor, $share->getSharedBy());
		if (!$cross) {
			return false;
		}
		$writable = ($share->getPermissions() & (\OCP\Constants::PERMISSION_CREATE | \OCP\Constants::PERMISSION_UPDATE | \OCP\Constants::PERMISSION_DELETE)) !== 0;
		return !$readOnlyViewer || $writable;
	}

	/**
	 * Jeton public de l'Agenda : page de prise de rendez-vous (jeton de
	 * configuration ou de réservation) ou agenda public partagé. Bloqué si son
	 * propriétaire est dans une autre cloison (il exposerait son nom et ses
	 * disponibilités à un compte connecté d'une autre cloison).
	 */
	public function calendarBlocked(string $actor, string $token): bool {
		if (array_key_exists('c:' . $token, $this->ownerCache)) {
			$owner = $this->ownerCache['c:' . $token];
			return $owner !== false && $this->crossZone($actor, (string)$owner);
		}
		$owner = false;
		// 1. jeton de configuration de rendez-vous
		try {
			$owner = Server::get(\OCA\Calendar\Db\AppointmentConfigMapper::class)->findByToken($token)->getUserId();
		} catch (\Throwable) {
			// 2. jeton de réservation → configuration → propriétaire
			try {
				$booking = Server::get(\OCA\Calendar\Service\Appointments\BookingService::class)->findByToken($token);
				$owner = Server::get(\OCA\Calendar\Service\Appointments\AppointmentConfigService::class)
					->findById($booking->getApptConfigId())->getUserId();
			} catch (\Throwable) {
				// 3. agenda public partagé (principaluri du propriétaire)
				try {
					$cal = Server::get(\OCA\DAV\CalDAV\CalDavBackend::class)->getPublicCalendar($token);
					if (preg_match('#^principals/users/(.+)$#', (string)($cal['principaluri'] ?? ''), $m)) {
						$owner = $m[1];
					}
				} catch (\Throwable) {
					$owner = false;
				}
			}
		}
		$this->ownerCache['c:' . $token] = $owner;
		return $owner !== false && $this->crossZone($actor, (string)$owner);
	}

	private function formBlocked(string $actor, string $hash): bool {
		if (!array_key_exists('f:' . $hash, $this->ownerCache)) {
			$owner = false;
			try {
				$shareMapper = Server::get(\OCA\Forms\Db\ShareMapper::class);
				$formMapper = Server::get(\OCA\Forms\Db\FormMapper::class);
				try {
					$share = $shareMapper->findPublicShareByHash($hash);
					$owner = $formMapper->findById($share->getFormId())->getOwnerId();
				} catch (\Throwable) {
					$owner = $formMapper->findByHash($hash)->getOwnerId();
				}
			} catch (\Throwable) {
				$owner = false;
			}
			$this->ownerCache['f:' . $hash] = $owner;
		}
		$owner = $this->ownerCache['f:' . $hash];
		return $owner !== false && $this->crossZone($actor, (string)$owner);
	}

	private function talkBlocked(RuleContext $ctx, string $token): bool {
		return !\OCA\Cloisons\Service\TalkHelper::canJoin($ctx, $token);
	}
}
