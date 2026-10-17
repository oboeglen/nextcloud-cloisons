<?php

declare(strict_types=1);

namespace OCA\Cloisons\Listener;

use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\ZoneGroupService;
use OCA\Cloisons\Service\ZoneService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserAddedEvent;

/**
 * Un groupe miroir ne contient que les membres de sa cloison : un ajout manuel
 * d'un compte extérieur (gestion des comptes, occ, panel) est annulé aussitôt,
 * avant que Talk, les partages ou les agendas ne lui ouvrent quoi que ce soit.
 *
 * @template-implements IEventListener<UserAddedEvent>
 */
class GroupMembershipListener implements IEventListener {
	public function __construct(
		private ZoneService $zones,
		private Policy $policy,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserAddedEvent) {
			return;
		}
		$group = $event->getGroup();
		$zone = ZoneGroupService::zoneOfGroup($group->getGID());
		if ($zone === null) {
			return;
		}
		$uid = $event->getUser()->getUID();
		if ($this->zones->zoneOf($uid) !== $zone) {
			$this->policy->block('zone-group:foreign-member', null, $uid);
			// toujours appliqué, même en mode audit : le groupe miroir n'existe que par l'application
			$group->removeUser($event->getUser());
		}
	}
}
