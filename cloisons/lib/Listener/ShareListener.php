<?php

declare(strict_types=1);

namespace OCA\Cloisons\Listener;

use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\Targets;
use OCA\Cloisons\Service\ZoneService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\IShare;

/**
 * Contrôle serveur de tout partage, quelle que soit l'interface ou l'API
 * utilisée : un partage ne peut viser que la cloison de son auteur.
 *
 * @template-implements IEventListener<BeforeShareCreatedEvent>
 */
class ShareListener implements IEventListener {
	/** Message identique pour un compte caché et un compte inexistant. */
	public const ERROR = 'Sharing failed, because the user %s does not exist';

	public function __construct(
		private ZoneService $zones,
		private Policy $policy,
		private Targets $targets,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeShareCreatedEvent) {
			return;
		}
		$share = $event->getShare();
		$actor = $share->getSharedBy() ?: $share->getShareOwner();
		if ($actor === '' || $actor === null) {
			return;
		}
		$with = (string)$share->getSharedWith();
		$deny = null;

		switch ($share->getShareType()) {
			case IShare::TYPE_USER:
				foreach (array_unique([$share->getSharedBy(), $share->getShareOwner()]) as $who) {
					if ($who && !$this->zones->canSee($who, $with)) {
						$deny = 'share:user';
					}
				}
				break;
			case IShare::TYPE_GROUP:
				foreach (array_unique([$share->getSharedBy(), $share->getShareOwner()]) as $who) {
					if ($who && !$this->zones->isGroupWithinZone($who, $with)) {
						$deny = 'share:group';
					}
				}
				break;
			case IShare::TYPE_REMOTE:
			case IShare::TYPE_REMOTE_GROUP:
				// Une fédération vers ce même serveur relierait deux cloisons.
				if ($this->targets->isLocalCloudId($with)) {
					$deny = 'share:federated-local';
				}
				break;
			case IShare::TYPE_CIRCLE:
				if (!$this->targets->isCircleWithinZone($actor, $with)) {
					$deny = 'share:team';
				}
				break;
			case IShare::TYPE_EMAIL:
			case IShare::TYPE_LINK:
			case IShare::TYPE_ROOM:
			case IShare::TYPE_DECK:
				// Lien public, e-mail externe, salon Talk (déjà limité à la cloison).
				break;
			default:
				$deny = 'share:type-' . $share->getShareType();
		}

		if ($deny !== null && $this->policy->block($deny, $actor, $with)) {
			$event->setError(sprintf(self::ERROR, $with));
			$event->stopPropagation();
		}
	}
}
