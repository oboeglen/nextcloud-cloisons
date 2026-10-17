<?php

declare(strict_types=1);

namespace OCA\Cloisons\Service;

use OCA\Cloisons\Middleware\RuleContext;
use OCP\Server;

/** Rattachement d'un salon Talk à une cloison (via ses propriétaires et modérateurs). */
class TalkHelper {
	/** @var array<string, list<string>|null> */
	private static array $owners = [];

	public static function ghostToken(): string {
		// format valide (4 à 30 caractères [a-z0-9]) mais aucun salon ne porte ce jeton
		return 'zz' . substr(str_replace(['-', '_'], '', strtolower(hash('sha256', Server::get(ZoneService::class)->ghostUid()))), 0, 26);
	}

	/** @return list<string>|null uids propriétaires/modérateurs, null si salon introuvable */
	private static function moderators(string $token): ?array {
		if (array_key_exists($token, self::$owners)) {
			return self::$owners[$token];
		}
		try {
			$manager = Server::get(\OCA\Talk\Manager::class);
			$room = $manager->getRoomByToken($token);
			$ps = Server::get(\OCA\Talk\Service\ParticipantService::class);
			$uids = [];
			foreach ($ps->getParticipantsForRoom($room) as $p) {
				$a = $p->getAttendee();
				if ($a->getActorType() === 'users' && in_array($a->getParticipantType(), [1, 2], true)) {
					$uids[] = $a->getActorId();
				}
			}
			return self::$owners[$token] = $uids;
		} catch (\Throwable) {
			return self::$owners[$token] = null;
		}
	}

	private static function isParticipant(string $token, string $uid): bool {
		try {
			$manager = Server::get(\OCA\Talk\Manager::class);
			$room = $manager->getRoomByToken($token);
			$ps = Server::get(\OCA\Talk\Service\ParticipantService::class);
			$ps->getParticipant($room, $uid, false);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/** Un salon « ouvert » (listable) n'est visible que dans la cloison de ses modérateurs. */
	public static function roomVisibleTo(RuleContext $c, string $token): bool {
		if ($c->actor === null || $token === '') {
			return false;
		}
		$mods = self::moderators($token);
		if ($mods === null || $mods === []) {
			return false;
		}
		foreach ($mods as $m) {
			if ($c->zones->canSee($c->actor, $m)) {
				return true;
			}
		}
		return false;
	}

	/** Rejoindre : déjà participant, salon public par lien, ou salon de sa cloison. */
	public static function canJoin(RuleContext $c, string $token): bool {
		if ($c->actor === null) {
			return true;
		}
		// mis en cache 5 min : les requêtes Talk à jeton sont très fréquentes (sondage du fil)
		$cacheFactory = Server::get(\OCP\ICacheFactory::class);
		$cache = $cacheFactory->isAvailable() ? $cacheFactory->createDistributed('cloisons-talk') : null;
		$key = md5($c->actor . '|' . $token);
		$hit = $cache?->get($key);
		if ($hit !== null) {
			return (bool)$hit;
		}
		$allowed = self::computeCanJoin($c, $token);
		$cache?->set($key, $allowed ? 1 : 0, 300);
		return $allowed;
	}

	private static function computeCanJoin(RuleContext $c, string $token): bool {
		try {
			Server::get(\OCA\Talk\Manager::class)->getRoomByToken($token);
		} catch (\Throwable) {
			return true; // salon inexistant : réponse native
		}
		if (self::isParticipant($token, $c->actor)) {
			return true;
		}
		// Salon d'une autre cloison, même public : refusé tant que le compte est
		// connecté (il resterait un invité anonyme sur une instance dédiée)
		return self::roomVisibleTo($c, $token);
	}
}
