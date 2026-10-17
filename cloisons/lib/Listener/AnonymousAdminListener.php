<?php

declare(strict_types=1);

namespace OCA\Cloisons\Listener;

use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\ZoneService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IUser;
use OCP\User\Events\PasswordUpdatedEvent;
use OCP\User\Events\UserChangedEvent;

/**
 * Un administrateur d'une autre cloison qui ajoute un compte à un groupe, l'en
 * retire, change son mot de passe ou son adresse : Nextcloud nommerait cet
 * administrateur dans l'activité et l'e-mail de sécurité du compte. Pendant le
 * traitement de l'événement, la session est rendue anonyme (comme une action
 * occ) : Nextcloud écrit alors de lui-même « Un administrateur… ».
 *
 * Écouteur enregistré deux fois : priorité haute (début) et basse (fin).
 *
 * @template-implements IEventListener<Event>
 */
class AnonymousAdminListener implements IEventListener {
	private static int $depth = 0;

	public function __construct(
		private ZoneService $zones,
		private Policy $policy,
	) {
	}

	public static function supported(): bool {
		return class_exists(\OC_User::class) && method_exists(\OC_User::class, 'setIncognitoMode');
	}

	private function target(Event $event): ?IUser {
		return match (true) {
			$event instanceof UserAddedEvent, $event instanceof UserRemovedEvent,
			$event instanceof PasswordUpdatedEvent, $event instanceof UserChangedEvent => $event->getUser(),
			default => null,
		};
	}

	/** Début du traitement de l'événement (priorité haute). */
	public function handle(Event $event): void {
		if (!self::supported()) {
			return;
		}
		if (self::$depth > 0) {
			self::$depth++; // événement imbriqué pendant une action déjà anonymisée
			return;
		}
		$target = $this->target($event);
		$actor = $this->zones->currentUid();
		if ($target === null || $actor === null || $actor === $target->getUID()) {
			return;
		}
		if (!$this->zones->canSee($actor, $target->getUID()) && $this->policy->enforcing()) {
			self::$depth = 1;
			\OC_User::setIncognitoMode(true);
		}
	}

	/** Fin du traitement de l'événement (priorité basse) : session rétablie. */
	public static function end(): void {
		if (self::$depth === 0) {
			return;
		}
		self::$depth--;
		if (self::$depth === 0) {
			\OC_User::setIncognitoMode(false);
		}
	}

	/**
	 * Filet de sécurité : rétablit la session si le mode anonyme est resté actif
	 * (une exception entre les deux écouteurs aurait sauté le rétablissement).
	 * Appelé en fin de traitement de chaque contrôleur et de chaque exception.
	 */
	public static function forceReset(): void {
		if (self::$depth !== 0 && self::supported()) {
			self::$depth = 0;
			\OC_User::setIncognitoMode(false);
		}
	}
}
