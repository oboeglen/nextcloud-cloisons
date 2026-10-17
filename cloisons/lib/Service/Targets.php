<?php

declare(strict_types=1);

namespace OCA\Cloisons\Service;

use OCP\Federation\ICloudIdManager;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

/** Résolution des cibles (identifiants fédérés, équipes, adresses e-mail). */
class Targets {
	/** @var list<string>|null */
	private ?array $localHosts = null;

	public function __construct(
		private ZoneService $zones,
		private ICloudIdManager $cloudIdManager,
		private IURLGenerator $urlGenerator,
		private IConfig $config,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<string> hôtes (sans schéma) sous lesquels ce serveur répond */
	private function localHosts(): array {
		if ($this->localHosts !== null) {
			return $this->localHosts;
		}
		$hosts = [];
		$add = function (string $url) use (&$hosts) {
			$url = strtolower(trim($url));
			$url = preg_replace('#^https?://#', '', $url);
			$url = rtrim((string)$url, '/');
			if ($url !== '') {
				$hosts[] = $url;
				$hosts[] = preg_replace('#/index\.php$#', '', $url);
				$hosts[] = preg_replace('#:\d+$#', '', explode('/', $url)[0]);
			}
		};
		$add($this->urlGenerator->getAbsoluteURL('/'));
		$add((string)$this->config->getSystemValue('overwrite.cli.url', ''));
		foreach ((array)$this->config->getSystemValue('trusted_domains', []) as $d) {
			$add((string)$d);
		}
		$hosts = array_merge($hosts, ['localhost', '127.0.0.1', '::1', '[::1]']);
		return $this->localHosts = array_values(array_unique(array_filter($hosts)));
	}

	public function isLocalHost(string $remote): bool {
		$r = strtolower(rtrim((string)preg_replace('#^https?://#', '', trim($remote)), '/'));
		$r = (string)preg_replace('#/index\.php$#', '', $r);
		$bare = (string)preg_replace('#:\d+$#', '', explode('/', $r)[0]);
		foreach ($this->localHosts() as $h) {
			if ($r === $h || $bare === $h || $bare === (string)preg_replace('#:\d+$#', '', explode('/', $h)[0])) {
				return true;
			}
		}
		return false;
	}

	/** Un identifiant fédéré qui pointe vers ce serveur (quel que soit le compte). */
	public function isLocalCloudId(string $cloudId): bool {
		try {
			$id = $this->cloudIdManager->resolveCloudId($cloudId);
			return $this->isLocalHost($id->getRemote());
		} catch (\Throwable) {
			$at = strrpos($cloudId, '@');
			return $at !== false && $this->isLocalHost(substr($cloudId, $at + 1));
		}
	}

	/**
	 * Compte local désigné par un identifiant fédéré (« uid@ce-serveur »), sinon null.
	 */
	public function localUserFromCloudId(string $cloudId): ?string {
		if (!$this->isLocalCloudId($cloudId)) {
			return null;
		}
		try {
			return $this->cloudIdManager->resolveCloudId($cloudId)->getUser();
		} catch (\Throwable) {
			return substr($cloudId, 0, (int)strrpos($cloudId, '@'));
		}
	}

	/** Comptes locaux portant cette adresse e-mail. @return list<string> */
	public function usersByEmail(string $email): array {
		$email = trim($email);
		if ($email === '' || !str_contains($email, '@')) {
			return [];
		}
		return array_values(array_map(fn ($u) => $u->getUID(), $this->userManager->getByEmail($email)));
	}

	/** Tous les membres (hérités) d'une équipe sont-ils dans la cloison de $viewer ? */
	public function isCircleWithinZone(string $viewer, string $circleId): bool {
		$members = $this->circleUserIds($circleId);
		if ($members === null) {
			return false;
		}
		$peers = array_flip($this->zones->peers($viewer));
		foreach ($members as $uid) {
			if (!isset($peers[$uid])) {
				return false;
			}
		}
		return true;
	}

	/**
	 * L'équipe existe-t-elle ? Une équipe inexistante reçoit la réponse native de
	 * Nextcloud, sans fantôme (sinon chaque identifiant inventé ajouterait une
	 * ligne dans la table des fantômes). Toute erreur inattendue est traitée
	 * comme « existe » : l'équipe est alors cachée.
	 */
	public function circleExists(string $circleId): bool {
		if ($circleId === '') {
			return false;
		}
		try {
			/** @var \OCA\Circles\CirclesManager $cm */
			$cm = Server::get(\OCA\Circles\CirclesManager::class);
			$cm->startSuperSession();
			try {
				$cm->getCircle($circleId);
				return true;
			} finally {
				$cm->stopSession();
			}
		} catch (\OCA\Circles\Exceptions\CircleNotFoundException) {
			return false;
		} catch (\Throwable) {
			return true;
		}
	}

	/** @return list<string>|null uids locaux membres (hérités) de l'équipe, null si introuvable ou non local */
	public function circleUserIds(string $circleId): ?array {
		try {
			/** @var \OCA\Circles\CirclesManager $cm */
			$cm = Server::get(\OCA\Circles\CirclesManager::class);
			$cm->startSuperSession();
			try {
				$circle = $cm->getCircle($circleId);
				$uids = [];
				foreach ($circle->getInheritedMembers() as $m) {
					// 1 = compte local ; tout autre type (e-mail, contact, distant…) sort de la cloison
					if ($m->getUserType() !== 1 || !$this->isLocalHost((string)$m->getInstance() ?: 'localhost') && $m->getInstance() !== '') {
						return null;
					}
					$uids[] = $m->getUserId();
				}
				return array_values(array_unique($uids));
			} finally {
				$cm->stopSession();
			}
		} catch (\Throwable) {
			return null; // équipe introuvable : traitée comme hors de la cloison
		}
	}
}
