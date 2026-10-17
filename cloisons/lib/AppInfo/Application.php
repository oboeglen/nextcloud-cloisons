<?php

declare(strict_types=1);

namespace OCA\Cloisons\AppInfo;

use OCA\Cloisons\Listener\AnonymousAdminListener;
use OCA\Cloisons\Listener\AutoCompleteListener;
use OCA\Cloisons\Listener\GroupMembershipListener;
use OCA\Cloisons\Listener\SabreListener;
use OCA\Cloisons\Listener\ShareListener;
use OCA\Cloisons\Listener\UserDeletedListener;
use OCA\Cloisons\Middleware\IsolationMiddleware;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Collaboration\AutoComplete\AutoCompleteFilterEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\User\Events\PasswordUpdatedEvent;
use OCP\User\Events\UserChangedEvent;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'cloisons';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// global : s'applique aux contrôleurs de toutes les applications et du cœur
		$context->registerMiddleware(IsolationMiddleware::class, true);
		$context->registerEventListener(BeforeShareCreatedEvent::class, ShareListener::class);
		$context->registerEventListener(AutoCompleteFilterEvent::class, AutoCompleteListener::class);
		$context->registerEventListener(SabrePluginAddEvent::class, SabreListener::class);
		// Enregistré par NOM de classe, sans class_exists : l'autoloader de Talk
		// (spreed) n'est pas encore actif quand Cloisons s'enregistre (ordre
		// alphabétique). Après UserMention (priorité -100 côté Talk).
		$context->registerEventListener('OCA\Talk\Events\MessageParseEvent', \OCA\Cloisons\Listener\MentionParseListener::class, -200);
		// Nextcloud 35 : filtre officiel des listes de comptes (par nom : classe absente en 34)
		$context->registerEventListener(\OCA\Cloisons\Listener\UserEnumerationListener::EVENT, \OCA\Cloisons\Listener\UserEnumerationListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(UserAddedEvent::class, GroupMembershipListener::class);
		// administrateur d'une autre cloison : actions anonymisées (début, puis fin)
		foreach ([UserAddedEvent::class, UserRemovedEvent::class, PasswordUpdatedEvent::class, UserChangedEvent::class] as $ev) {
			$context->registerEventListener($ev, AnonymousAdminListener::class, 100000);
			$context->registerEventListener($ev, \OCA\Cloisons\Listener\AnonymousAdminEndListener::class, -100000);
		}
	}

	public function boot(IBootContext $context): void {
	}
}
