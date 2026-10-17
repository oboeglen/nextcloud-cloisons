<?php

declare(strict_types=1);

namespace OCA\Cloisons\Listener;

use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\ZoneService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Nextcloud 35 : événement officiel émis quand une liste de comptes est rendue
 * à l'utilisateur (statuts…). Les comptes d'une autre cloison en sont retirés :
 * seconde barrière derrière le middleware. Jamais émis par Nextcloud 34.
 *
 * Enregistré par nom de classe (OCP\User\Events\UserEnumerationFilterEvent).
 *
 * @template-implements IEventListener<Event>
 */
class UserEnumerationListener implements IEventListener {
	public const EVENT = 'OCP\User\Events\UserEnumerationFilterEvent';

	public function __construct(
		private ZoneService $zones,
		private Policy $policy,
	) {
	}

	public function handle(Event $event): void {
		if (!is_a($event, self::EVENT) || !$this->policy->enforcing()) {
			return;
		}
		$actor = $this->zones->currentUid();
		if ($actor === null) {
			return;
		}
		$users = $event->getUsers();
		$kept = array_values(array_filter($users, fn ($uid) => !is_string($uid) || $this->zones->canSee($actor, $uid)));
		if (count($kept) !== count($users)) {
			$event->setUsers($kept);
		}
	}
}
