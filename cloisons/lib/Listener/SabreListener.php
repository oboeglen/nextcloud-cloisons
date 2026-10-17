<?php

declare(strict_types=1);

namespace OCA\Cloisons\Listener;

use OCA\Cloisons\Dav\IsolationPlugin;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Server;

/** @template-implements IEventListener<SabrePluginAddEvent> */
class SabreListener implements IEventListener {
	public function handle(Event $event): void {
		if ($event instanceof SabrePluginAddEvent) {
			$event->getServer()->addPlugin(Server::get(IsolationPlugin::class));
		}
	}
}
