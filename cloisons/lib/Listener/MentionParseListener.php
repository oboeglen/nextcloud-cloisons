<?php

declare(strict_types=1);

namespace OCA\Cloisons\Listener;

use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\Targets;
use OCA\Cloisons\Service\ZoneService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Mentions Talk (@compte) vers un compte d'une AUTRE cloison.
 *
 * Talk résout le nom affiché de n'importe quel compte, qu'il soit ou non
 * participant du salon : une mention `@alice` écrite par un client afficherait
 * donc « Alice Martin » (nom réel + preuve d'existence). On annule la
 * transformation en objet enrichi pour ce compte : la mention redevient du
 * texte brut `@…`, exactement ce que Talk affiche pour un compte inexistant.
 *
 * Enregistré APRÈS UserMention (priorité plus basse) pour défaire son travail
 * sur les seules cibles cachées.
 *
 * @template-implements IEventListener<Event>
 */
class MentionParseListener implements IEventListener {
	public function __construct(
		private ZoneService $zones,
		private Policy $policy,
		private Targets $targets,
	) {
	}

	public function handle(Event $event): void {
		if (!$this->policy->enforcing() || !class_exists(\OCA\Talk\Events\MessageParseEvent::class)
			|| !($event instanceof \OCA\Talk\Events\MessageParseEvent)) {
			return;
		}
		$actor = $this->zones->currentUid();
		if ($actor === null) {
			return;
		}
		$message = $event->getMessage();
		$params = $message->getMessageParameters();
		if ($params === []) {
			return;
		}
		$text = $message->getMessage();
		// texte tel que saisi : pour restituer la mention à l'identique (@x ou @"x")
		$typed = (string)($message->getComment()?->getMessage() ?? '');
		$changed = false;
		foreach ($params as $key => $p) {
			if (!is_array($p) || !isset($p['id'])) {
				continue;
			}
			$id = (string)$p['id'];
			$type = (string)($p['type'] ?? '');
			$hidden = match ($type) {
				'user' => isset($p['server'])
					? $this->targets->isLocalCloudId($id . '@' . $p['server']) && $this->hidden($actor, $id)
					: $this->hidden($actor, $id),
				'user-group' => \OCP\Server::get(\OCP\IGroupManager::class)->groupExists($id)
					&& !$this->zones->isGroupWithinZone($actor, $id),
				'circle' => !$this->targets->isCircleWithinZone($actor, $id),
				default => false,
			};
			if (!$hidden) {
				continue;
			}
			// jeton d'origine saisi par l'auteur (ex. « group/gid », « alice »)
			$raw = (string)($p['mention-id'] ?? $id);
			$form = str_contains($typed, '@"' . $raw . '"') || preg_match('/[\s"]/', $raw) ? '@"' . $raw . '"' : '@' . $raw;
			$text = str_replace('{' . $key . '}', $form, $text);
			unset($params[$key]);
			$changed = true;
			$this->policy->block('talk:mention', $actor, $id);
		}
		if ($changed) {
			$message->setMessage($text, $params, $message->getMessageRaw());
		}
	}

	private function hidden(string $actor, string $uid): bool {
		return !$this->zones->canSee($actor, $uid)
			&& \OCP\Server::get(\OCP\IUserManager::class)->userExists($uid);
	}
}
