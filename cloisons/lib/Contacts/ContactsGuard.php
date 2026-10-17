<?php

declare(strict_types=1);

namespace OCA\Cloisons\Contacts;

use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\ZoneService;
use OCP\Contacts\IManager;
use OCP\IUserManager;

/** Remplace, pour la requête en cours, le carnet système par sa vue cloisonnée. */
class ContactsGuard {
	private ?string $installedFor = null;

	public function __construct(
		private IManager $contactsManager,
		private ZoneService $zones,
		private Policy $policy,
		private IUserManager $userManager,
	) {
	}

	public function install(): void {
		$actor = $this->zones->currentUid();
		if ($actor === null || $this->installedFor === $actor) {
			return;
		}
		$this->installedFor = $actor;
		foreach ($this->contactsManager->getUserAddressBooks() as $book) {
			if ($book instanceof ZoneAddressBook || !$book->isSystemAddressBook()) {
				continue;
			}
			$this->contactsManager->unregisterAddressBook($book);
			$this->contactsManager->registerAddressBook(new ZoneAddressBook($book, $this->zones, $this->policy, $this->userManager, $actor));
		}
	}
}
