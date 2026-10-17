<?php

declare(strict_types=1);

namespace OCA\Cloisons\Contacts;

use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\ZoneService;
use OCP\IAddressBook;
use OCP\IUserManager;

/**
 * Carnet système vu à travers la cloison du compte connecté : les cartes des
 * comptes d'autres cloisons sont absentes de toute recherche interne
 * (suggestions, nom affiché d'un partage par e-mail, participants d'agenda…).
 */
class ZoneAddressBook implements IAddressBook {
	public function __construct(
		private IAddressBook $inner,
		private ZoneService $zones,
		private Policy $policy,
		private IUserManager $userManager,
		private string $actor,
	) {
	}

	public function getKey() {
		return $this->inner->getKey();
	}

	public function getUri(): string {
		return $this->inner->getUri();
	}

	public function getDisplayName() {
		return $this->inner->getDisplayName();
	}

	public function search($pattern, $searchProperties, $options) {
		$results = $this->inner->search($pattern, $searchProperties, $options);
		if (!$this->policy->enforcing()) {
			return $results;
		}
		$out = [];
		foreach ($results as $card) {
			$uid = (string)($card['UID'] ?? '');
			if ($uid !== '' && $uid !== $this->actor && !$this->zones->canSee($this->actor, $uid) && $this->userManager->userExists($uid)) {
				continue;
			}
			$out[] = $card;
		}
		return $out;
	}

	public function createOrUpdate($properties) {
		return $this->inner->createOrUpdate($properties);
	}

	public function getPermissions() {
		return $this->inner->getPermissions();
	}

	public function delete($id) {
		return $this->inner->delete($id);
	}

	public function isShared(): bool {
		return $this->inner->isShared();
	}

	public function isSystemAddressBook(): bool {
		return $this->inner->isSystemAddressBook();
	}
}
