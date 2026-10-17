<?php

declare(strict_types=1);

namespace OCA\Cloisons\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @template-implements IEventListener<Event> */
class AnonymousAdminEndListener implements IEventListener {
	public function handle(Event $event): void {
		AnonymousAdminListener::end();
	}
}
