<?php

declare(strict_types=1);

namespace OCA\Cloisons\Middleware;

use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\Targets;
use OCA\Cloisons\Service\ZoneService;
use OCP\IRequest;
use OCP\Server;

/** Contexte d'évaluation d'une requête : acteur, décisions, substitutions. */
class RuleContext {
	public const KIND_UID = 'uid';
	public const KIND_UIDS = 'uids';           // tableau ou JSON de uids
	public const KIND_CLOUDID = 'cloudid';     // uid@serveur
	public const KIND_EMAIL = 'email';
	public const KIND_EMAILS = 'emails';
	public const KIND_GROUP = 'group';
	public const KIND_CIRCLE = 'circle';

	/** @var array<string, string> fantôme => valeur d'origine */
	private array $echo = [];
	private bool $denied = false;
	private ?Targets $targets = null;

	public function __construct(
		public IRequest $request,
		public ZoneService $zones,
		public Policy $policy,
		public ?string $actor,
		public string $vector,
	) {
	}

	public function targets(): Targets {
		return $this->targets ??= Server::get(Targets::class);
	}

	public function echoMap(): array {
		return $this->echo;
	}

	public function deny(string $why, ?string $target = null): void {
		if ($this->policy->block($this->vector . ':' . $why, $this->actor, $target)) {
			$this->denied = true;
		}
	}

	public function denied(): bool {
		return $this->denied;
	}

	/** Le compte $uid est-il invisible pour l'acteur ? (compte inexistant = visible : réponse native) */
	public function hiddenUser(?string $uid): bool {
		if ($uid === null || $uid === '' || $this->actor === null) {
			return false;
		}
		if ($this->zones->canSee($this->actor, $uid)) {
			return false;
		}
		// Un uid qui n'est pas un compte existant est laissé à Nextcloud (réponse native)
		return \OCP\Server::get(\OCP\IUserManager::class)->userExists($uid);
	}

	public function hiddenGroup(?string $gid): bool {
		if ($gid === null || $gid === '' || $this->actor === null) {
			return false;
		}
		if (!\OCP\Server::get(\OCP\IGroupManager::class)->groupExists($gid)) {
			return false;
		}
		return !$this->zones->isGroupWithinZone($this->actor, $gid);
	}

	public function hiddenCircle(?string $cid): bool {
		if ($cid === null || $cid === '' || $this->actor === null) {
			return false;
		}
		return !$this->targets()->isCircleWithinZone($this->actor, $cid) && $this->targets()->circleExists($cid);
	}

	public function hiddenEmail(?string $email): bool {
		if ($email === null || $this->actor === null) {
			return false;
		}
		foreach ($this->targets()->usersByEmail($email) as $uid) {
			if (!$this->zones->canSee($this->actor, $uid)) {
				return true;
			}
		}
		return false;
	}

	public function hiddenCloudId(?string $cloudId): bool {
		if ($cloudId === null || $this->actor === null) {
			return false;
		}
		$uid = $this->targets()->localUserFromCloudId($cloudId);
		return $uid !== null && $this->hiddenUser($uid);
	}

	// ------------------------------------------------------------ substitution

	public function ghostUid(string $original): string {
		$g = $this->zones->ghostFor($original, 'uid', $this->actor);
		$this->echo[$g] = $original;
		$this->policy->block($this->vector, $this->actor, $original);
		return $g;
	}

	public function ghostGroup(string $original): string {
		$g = $this->zones->ghostFor($original, 'group', $this->actor);
		$this->echo[$g] = $original;
		$this->policy->block($this->vector . ':group', $this->actor, $original);
		return $g;
	}

	public function ghostEmail(string $original): string {
		$g = $this->zones->ghostFor($original, 'email', $this->actor);
		$this->echo[$g] = $original;
		$this->policy->block($this->vector . ':email', $this->actor, $original);
		return $g;
	}

	public function ghostCloudId(string $original): string {
		$g = $this->zones->ghostFor($original, 'cloudid', $this->actor);
		$this->echo[$g] = $original;
		$this->policy->block($this->vector . ':cloudid', $this->actor, $original);
		return $g;
	}

	public function ghostCircle(string $original): string {
		$g = $this->zones->ghostFor($original, 'team', $this->actor);
		$this->echo[$g] = $original;
		$this->policy->block($this->vector . ':team', $this->actor, $original);
		return $g;
	}

	/** Valeur nettoyée d'un paramètre (inchangée si rien n'est caché). */
	public function sanitizeParam(string $kind, string $name, mixed $value): mixed {
		switch ($kind) {
			case self::KIND_UID:
				return is_string($value) && $this->hiddenUser($value) ? $this->ghostUid($value) : $value;
			case self::KIND_CLOUDID:
				if (!is_string($value)) {
					return $value;
				}
				if ($this->hiddenCloudId($value)) {
					return $this->ghostCloudId($value);
				}
				return $this->hiddenUser($value) ? $this->ghostUid($value) : $value;
			case self::KIND_EMAIL:
				return is_string($value) && $this->hiddenEmail($value) ? $this->ghostEmail($value) : $value;
			case self::KIND_GROUP:
				return is_string($value) && $this->hiddenGroup($value) ? $this->ghostGroup($value) : $value;
			case self::KIND_CIRCLE:
				return is_string($value) && $this->hiddenCircle($value) ? $this->ghostCircle($value) : $value;
			case self::KIND_UIDS:
			case self::KIND_EMAILS:
				$json = false;
				$list = $value;
				if (is_string($value)) {
					$decoded = json_decode($value, true);
					if (is_array($decoded)) {
						$list = $decoded;
						$json = true;
					} else {
						$list = [$value];
					}
				}
				if (!is_array($list)) {
					return $value;
				}
				$changed = false;
				foreach ($list as $k => $v) {
					if (!is_string($v)) {
						continue;
					}
					$hidden = $kind === self::KIND_UIDS ? $this->hiddenUser($v) : $this->hiddenEmail($v);
					if ($hidden) {
						$list[$k] = $kind === self::KIND_UIDS ? $this->ghostUid($v) : $this->ghostEmail($v);
						$changed = true;
					}
				}
				if (!$changed) {
					return $value;
				}
				return $json ? json_encode($list) : (is_string($value) ? $list[0] : $list);
		}
		return $value;
	}

	/** Filtre une liste en ne gardant que les éléments visibles. */
	public function filterList(array $list, callable $isHidden): array {
		$out = [];
		$assoc = array_keys($list) !== range(0, count($list) - 1);
		foreach ($list as $k => $item) {
			if ($isHidden($item, $k)) {
				$this->policy->block($this->vector . ':list', $this->actor, is_string($k) ? $k : null);
				continue;
			}
			if ($assoc) {
				$out[$k] = $item;
			} else {
				$out[] = $item;
			}
		}
		return $out;
	}
}
