<?php

declare(strict_types=1);

namespace OCA\Cloisons\Listener;

use OCA\Cloisons\Service\ZoneService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/** @template-implements IEventListener<UserDeletedEvent> */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private ZoneService $zones,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			$this->zones->assign($event->getUid(), null);
		}
	}
}
