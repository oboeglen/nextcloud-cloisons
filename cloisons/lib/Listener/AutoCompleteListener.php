<?php

declare(strict_types=1);

namespace OCA\Cloisons\Listener;

use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\Targets;
use OCA\Cloisons\Service\ZoneService;
use OCP\Collaboration\AutoComplete\AutoCompleteFilterEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;

/**
 * Filtre les suggestions de l'autocomplétion (mentions, commentaires…) avant
 * leur tri : seconde barrière derrière le middleware.
 *
 * @template-implements IEventListener<AutoCompleteFilterEvent>
 */
class AutoCompleteListener implements IEventListener {
	public function __construct(
		private ZoneService $zones,
		private Policy $policy,
		private Targets $targets,
		private IGroupManager $groupManager,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof AutoCompleteFilterEvent || !$this->policy->enforcing()) {
			return;
		}
		$actor = $this->zones->currentUid();
		if ($actor === null) {
			return;
		}
		$results = $event->getResults();
		if (isset($results['exact']) && is_array($results['exact'])) {
			foreach ($results['exact'] as $type => $list) {
				$results['exact.' . $type] = $list;
			}
			unset($results['exact']);
			$nested = true;
		}
		foreach ($results as $type => &$list) {
			if (!is_array($list)) {
				continue;
			}
			$list = array_values(array_filter($list, function ($e) use ($type, $actor) {
				$with = (string)($e['value']['shareWith'] ?? '');
				return match ($type) {
					'users', 'exact.users' => $with === '' || $this->zones->canSee($actor, $with),
					'groups', 'exact.groups' => $with === '' || !$this->groupManager->groupExists($with) || $this->zones->isGroupWithinZone($actor, $with),
					'circles', 'exact.circles' => $with === '' || $this->targets->isCircleWithinZone($actor, $with),
					'remotes', 'exact.remotes' => !$this->targets->isLocalCloudId($with),
					default => true,
				};
			}));
		}
		unset($list);
		if (!empty($nested)) {
			foreach (array_keys($results) as $k) {
				if (str_starts_with((string)$k, 'exact.')) {
					$results['exact'][substr((string)$k, 6)] = $results[$k];
					unset($results[$k]);
				}
			}
		}
		$event->setResults($results);
	}
}
