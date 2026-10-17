<?php

declare(strict_types=1);

namespace OCA\Cloisons\Service;

use OCA\Cloisons\Middleware\ResponseData;
use OCA\Cloisons\Middleware\RuleContext as K;
use OCA\Cloisons\Middleware\ShortCircuit;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IUserManager;
use OCP\Server;

/**
 * Table de certification : pour chaque route qui manipule des comptes, les
 * paramètres qui désignent une personne et le filtrage de la réponse.
 * Une route absente de cette table n'est pas modifiée.
 *
 * Clés : 'Classe::méthode' => [
 *   'vector'      => nom court (journal),
 *   'params'      => [nomDuParamètre => type (RuleContext::KIND_*)],
 *   'before'      => fn(RuleContext): ?array  substitutions conditionnelles,
 *   'after'       => fn(RuleContext, Response): ?Response  filtrage,
 *   'adminExempt' => true si les administrateurs ne sont pas filtrés,
 *   'anonymous'   => true si la règle s'applique aussi sans session,
 *   'since'       => version majeure de Nextcloud où la route apparaît (autotest),
 * ]
 */
class Rules {
	private ?array $table = null;

	public function forController(string $class, string $method): ?array {
		$this->table ??= $this->build();
		return $this->table[$class . '::' . strtolower($method)] ?? null;
	}

	/** Version majeure de Nextcloud à partir de laquelle la route existe (0 = toutes). */
	public function since(string $key): int {
		$this->table ??= $this->build();
		return (int)($this->table[$key]['since'] ?? 0);
	}

	/** @return list<string> routes certifiées (pour l'interface et l'autotest) */
	public function certified(): array {
		$this->table ??= $this->build();
		return array_keys($this->table);
	}

	private function build(): array {
		$t = [];
		$add = function (string $class, array $methods, array $spec) use (&$t) {
			foreach ($methods as $m) {
				$t[$class . '::' . strtolower($m)] = $spec + ['vector' => substr(strrchr('\\' . $class, '\\'), 1) . '.' . $m];
			}
		};

		// ------------------------------------------------------------------ cœur
		$add('OC\Core\Controller\AvatarController', ['getAvatar', 'getAvatarDark'], ['params' => ['userId' => K::KIND_UID]]);
		$add('OCA\Profile\Controller\ProfilePageController', ['index'], ['params' => ['targetUserId' => K::KIND_UID]]);
		// Profile 2 (Nextcloud 35) : ressources partagées avec un compte
		$add('OCA\Profile\Controller\ProfileApiController', ['getResources'], ['params' => ['userId' => K::KIND_UID], 'since' => 35]);
		$add('OC\Core\Controller\ProfileApiController', ['getProfileFields', 'setVisibility'], ['params' => ['targetUserId' => K::KIND_UID]]);
		$add('OC\Core\Controller\HoverCardController', ['getUser'], ['params' => ['userId' => K::KIND_UID]]);
		$add('OC\Core\Controller\UserController', ['getDisplayNames'], ['params' => ['users' => K::KIND_UIDS]]);
		$add('OC\Core\Controller\OCSController', ['getIdentityProof'], ['params' => ['cloudId' => K::KIND_CLOUDID]]);
		$add('OC\Core\Controller\ContactsMenuController', ['findOne'], [
			'before' => function (K $c) {
				$type = (int)$c->request->getParam('shareType');
				$with = (string)$c->request->getParam('shareWith');
				if ($type === 0 && $c->hiddenUser($with)) {
					return ['shareWith' => $c->ghostUid($with)];
				}
				if ($type === 4 && $c->hiddenEmail($with)) {
					return ['shareWith' => $c->ghostEmail($with)];
				}
				return null;
			},
		]);
		$add('OC\Core\Controller\ContactsMenuController', ['index'], [
			'after' => fn (K $c, Response $r) => $this->mapData($r, function ($d) use ($c) {
				if (isset($d['contacts']) && is_array($d['contacts'])) {
					$d['contacts'] = $c->filterList($d['contacts'], fn ($e) => $this->contactEntryHidden($c, $e));
				}
				return $d;
			}),
		]);
		$add('OC\Core\Controller\ContactsMenuController', ['getTeams'], [
			'after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
				? $c->filterList($d, fn ($e) => is_array($e) && isset($e['teamId']) && $c->hiddenCircle((string)$e['teamId']))
				: $d),
		]);
		$add('OC\Core\Controller\AutoCompleteController', ['get'], [
			'after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d) ? $c->filterList($d, fn ($e) => $this->autocompleteHidden($c, $e)) : $d),
		]);
		$add('OC\Core\Controller\UnifiedSearchController', ['search'], [
			'after' => fn (K $c, Response $r) => $this->mapData($r, function ($d) use ($c) {
				if (isset($d['entries']) && is_array($d['entries'])) {
					$d['entries'] = $c->filterList($d['entries'], fn ($e) => $this->contactEntryHidden($c, $e));
				}
				return $d;
			}),
		]);

		// Aperçus de liens : un lien de profil (/u/<compte>) d'une autre cloison
		// est résolu comme celui d'un compte inexistant (sinon nom, e-mail, bio…)
		$add('OC\Core\Controller\ReferenceApiController', ['resolveOne', 'resolve', 'extract'], [
			'before' => function (K $c) {
				$fix = function ($text) use ($c) {
					if (!is_string($text)) {
						return $text;
					}
					return (string)preg_replace_callback('#(/u/)([^/?\#"\s<>]+)#', function ($m) use ($c) {
						$uid = rawurldecode($m[2]);
						return $c->hiddenUser($uid) ? $m[1] . rawurlencode($c->ghostUid($uid)) : $m[0];
					}, $text);
				};
				$out = [];
				foreach (['reference', 'text'] as $name) {
					$v = $c->request->getParam($name);
					if (is_string($v) && ($n = $fix($v)) !== $v) {
						$out[$name] = $n;
					}
				}
				$refs = $c->request->getParam('references');
				if (is_array($refs)) {
					$new = array_map($fix, $refs);
					if ($new !== $refs) {
						$out['references'] = $new;
					}
				}
				return $out ?: null;
			},
		]);

		// API de partage unifiée (application « sharing », Nextcloud 35, expérimentale,
		// désactivée par défaut) : aucun type de destinataire n'y est certifié. Si elle
		// est activée, elle est introuvable pour les comptes non administrateurs ;
		// désactivée, la réponse native (501) est conservée.
		$add('OCA\Sharing\Controller\ApiV1Controller', ['searchRecipients', 'createShare', 'updateShareState',
			'updateShareUserStatus', 'addShareSource', 'removeShareSource', 'addShareRecipient', 'removeShareRecipient',
			'updateShareRecipientSecret', 'updateShareProperty', 'updateSharePermission', 'updateShareRecipientPermission',
			'selectSharePermissionPreset', 'deleteShare', 'getShare', 'getShares'], ['since' => 35, 'adminExempt' => true, 'anonymous' => true,
			'before' => function (K $c) {
				if (\OCP\Server::get(\OCP\IConfig::class)->getSystemValueBool('sharing.unified_api_enable', false)) {
					$c->deny('unified-sharing');
				}
				return null;
			}]);

		// ------------------------------------------------------- provisioning
		$prov = 'OCA\Provisioning_API\Controller\UsersController';
		// Groupes d'un compte (Contacts › Équipes…) : un groupe contenant un compte
		// d'une autre cloison n'est pas affiché (aucun pour un client)
		$add($prov, ['getUsersGroups', 'getUsersGroupsDetails'], ['params' => ['userId' => K::KIND_UID], 'adminExempt' => true,
			'after' => fn (K $c, Response $r) => $this->mapData($r, function ($d) use ($c) {
				if (isset($d['groups']) && is_array($d['groups'])) {
					$d['groups'] = $c->filterList($d['groups'], fn ($g) => $c->zones->groupHasOutsiders((string)$c->actor,
						is_array($g) ? (string)($g['id'] ?? '') : (string)$g));
				}
				return $d;
			})]);
		$add($prov, ['getUser', 'getUserSubAdminGroupsDetails',
			'getEditableFieldsForUser', 'editUser', 'editUserMultiField', 'editUserMultiValue', 'wipeUserDevices',
			'deleteUser', 'enableUser', 'disableUser', 'addToGroup', 'removeFromGroup', 'resendWelcomeMessage',
			'getUserSubAdminGroups', 'addSubAdmin', 'removeSubAdmin'],
			['params' => ['userId' => K::KIND_UID], 'adminExempt' => true]);
		$add($prov, ['getUsers'], ['adminExempt' => true,
			'after' => fn (K $c, Response $r) => $this->mapData($r, function ($d) use ($c) {
				if (isset($d['users']) && is_array($d['users'])) {
					$d['users'] = $c->filterList($d['users'], fn ($u) => is_string($u) && $c->hiddenUser($u));
				}
				return $d;
			})]);
		$add($prov, ['getUsersDetails', 'getDisabledUsersDetails', 'getLastLoggedInUsers'], ['adminExempt' => true,
			'after' => fn (K $c, Response $r) => $this->mapData($r, function ($d) use ($c) {
				if (isset($d['users']) && is_array($d['users'])) {
					$d['users'] = $c->filterList($d['users'], fn ($u, $k) => $c->hiddenUser(is_string($k) ? $k : ($u['id'] ?? null)));
				}
				return $d;
			})]);
		$add($prov, ['searchByPhoneNumbers'], ['adminExempt' => true,
			'after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
				? $c->filterList($d, fn ($cloudId) => is_string($cloudId) && $c->hiddenCloudId($cloudId))
				: $d)]);
		$grp = 'OCA\Provisioning_API\Controller\GroupsController';
		// Liste des groupes : un compte non administrateur ne voit que ses propres groupes
		$add($grp, ['getGroups'], ['adminExempt' => true,
			'after' => fn (K $c, Response $r) => $this->mapData($r, function ($d) use ($c) {
				if (isset($d['groups']) && is_array($d['groups'])) {
					$mine = array_flip(Server::get(\OCP\IGroupManager::class)->getUserGroupIds(Server::get(IUserManager::class)->get((string)$c->actor)));
					$d['groups'] = $c->filterList($d['groups'], fn ($g) => is_string($g) && !isset($mine[$g]));
				}
				return $d;
			})]);
		$add($grp, ['getGroupsDetails'], ['adminExempt' => true,
			'after' => fn (K $c, Response $r) => $this->mapData($r, function ($d) use ($c) {
				if (isset($d['groups']) && is_array($d['groups'])) {
					$mine = array_flip(Server::get(\OCP\IGroupManager::class)->getUserGroupIds(Server::get(IUserManager::class)->get((string)$c->actor)));
					$d['groups'] = $c->filterList($d['groups'], fn ($g) => is_array($g) && !isset($mine[(string)($g['id'] ?? '')]));
				}
				return $d;
			})]);
		$add($grp, ['getGroupUsers', 'getGroup'], ['adminExempt' => true,
			'after' => fn (K $c, Response $r) => $this->mapData($r, function ($d) use ($c) {
				if (isset($d['users']) && is_array($d['users'])) {
					$d['users'] = $c->filterList($d['users'], fn ($u) => is_string($u) && $c->hiddenUser($u));
				}
				return $d;
			})]);
		$add($grp, ['getGroupUsersDetails'], ['adminExempt' => true,
			'after' => fn (K $c, Response $r) => $this->mapData($r, function ($d) use ($c) {
				if (isset($d['users']) && is_array($d['users'])) {
					$d['users'] = $c->filterList($d['users'], fn ($u, $k) => $c->hiddenUser(is_string($k) ? $k : ($u['id'] ?? null)));
				}
				return $d;
			})]);

		// --------------------------------------------------------- user_status
		$add('OCA\UserStatus\Controller\StatusesController', ['find'], ['params' => ['userId' => K::KIND_UID]]);
		$add('OCA\UserStatus\Controller\StatusesController', ['findAll'], [
			'after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
				? $c->filterList($d, fn ($s) => is_array($s) && $c->hiddenUser($s['userId'] ?? null)) : $d)]);
		$add('OCA\Dashboard\Controller\DashboardApiController', ['getStatuses'], [
			'after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
				? $c->filterList($d, fn ($s) => is_array($s) && $c->hiddenUser($s['userId'] ?? null)) : $d)]);

		// ---------------------------------------------------------------- dav
		$add('OCA\DAV\Controller\OutOfOfficeController', ['getCurrentOutOfOfficeData', 'getOutOfOffice'], ['params' => ['userId' => K::KIND_UID]]);
		$add('OCA\DAV\Controller\OutOfOfficeController', ['setOutOfOffice'], ['params' => ['replacementUserId' => K::KIND_UID]]);

		// ----------------------------------------------------------- partages
		$add('OCA\Files_Sharing\Controller\ShareAPIController', ['createShare'], [
			'before' => fn (K $c) => $this->shareTarget($c, 'shareType', 'shareWith'),
		]);
		$add('OCA\Files_Sharing\Controller\ShareesAPIController', ['search', 'findRecommended'], [
			'after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => $this->filterSharees($c, $d)),
		]);
		$add('OCA\Files\Controller\TransferOwnershipController', ['transfer'], ['params' => ['recipient' => K::KIND_UID]]);

		// ------------------------------------------------------------ calendar
		$add('OCA\Calendar\Controller\ContactController', ['searchAttendee'], [
			'after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
				? $c->filterList($d, fn ($e) => $this->contactEntryHidden($c, $e)) : $d)]);
		$add('OCA\Calendar\Controller\ContactController', ['getCircleMembers'], ['params' => ['circleId' => K::KIND_CIRCLE]]);
		$add('OCA\Calendar\Controller\AppointmentController', ['index'], ['params' => ['userId' => K::KIND_UID]]);

		// --------------------------------------------------------------- talk
		$room = 'OCA\Talk\Controller\RoomController';
		$add($room, ['createRoom'], ['before' => fn (K $c) => $this->talkCreate($c)]);
		$add($room, ['addParticipantToRoom'], ['before' => fn (K $c) => $this->talkParticipant($c, 'source', 'newParticipant')]);
		$add($room, ['getListedRooms'], [
			'after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
				? $c->filterList($d, fn ($room) => is_array($room) && !TalkHelper::roomVisibleTo($c, (string)($room['token'] ?? ''))) : $d)]);
		// joinRoom et toutes les routes à jeton Talk : PublicGuard
		$add('OCA\Talk\Controller\ChatController', ['mentions'], [
			'after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
				? $c->filterList($d, fn ($e) => $this->autocompleteHidden($c, $e)) : $d)]);
		$add('OCA\Talk\Controller\AvatarController', ['getUserProxyAvatarWithoutRoom', 'getUserProxyAvatarDarkWithoutRoom',
			'getUserProxyAvatar', 'getUserProxyAvatarDark'], ['params' => ['cloudId' => K::KIND_CLOUDID]]);

		// ------------------------------------------------------- équipes
		$loc = 'OCA\Circles\Controller\LocalController';
		$add($loc, ['memberAdd'], ['before' => fn (K $c) => $this->circleMember($c, 'userId', 'type')]);
		$add($loc, ['membersAdd'], ['before' => function (K $c) {
			$members = $c->request->getParam('members');
			if (!is_array($members)) {
				return null;
			}
			// membres cachés retirés de la liste (Teams ignore une cible inexistante)
			$kept = [];
			foreach ($members as $m) {
				$id = (string)($m['id'] ?? '');
				$type = (int)($m['type'] ?? 0);
				$hidden = match ($type) {
					1 => $c->hiddenUser($id) || $c->hiddenCloudId($id),
					2 => $c->hiddenGroup($id),
					4 => $c->hiddenEmail($id),
					16 => $c->hiddenCircle($id),
					default => false,
				};
				if ($hidden) {
					$c->policy->block($c->vector, $c->actor, $id);
					continue;
				}
				$kept[] = $m;
			}
			if (count($kept) === count($members) || !$c->policy->enforcing()) {
				return null;
			}
			if ($kept === []) {
				throw new ShortCircuit(new DataResponse([]));
			}
			return ['members' => $kept];
		}]);
		$add($loc, ['search'], ['after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
			? $c->filterList($d, fn ($e) => $this->circleSearchHidden($c, $e)) : $d)]);
		$add($loc, ['circles', 'probeCircles'], ['after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
			? $c->filterList($d, fn ($e) => is_array($e) && isset($e['id']) && !$this->actorInCircle($c, $e) && $c->hiddenCircle((string)$e['id'])) : $d)]);
		$add($loc, ['members'], ['after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
			? $c->filterList($d, fn ($e) => is_array($e) && (int)($e['userType'] ?? 0) === 1 && $c->hiddenUser($e['userId'] ?? null)) : $d)]);
		$add($loc, ['circleJoin'], ['params' => ['circleId' => K::KIND_CIRCLE]]);
		// Circles 35 (Nextcloud 35) : liens d'invitation. Le code d'une équipe d'une
		// autre cloison reçoit la réponse d'un code inconnu (ni nom, ni adhésion).
		$add($loc, ['getInvitation', 'joinInvitation'], ['since' => 35, 'before' => function (K $c) {
			$code = $c->request->getParam('invitationCode');
			$circleId = is_string($code) && $code !== '' ? $this->circleByInvitation($code) : null;
			if ($circleId === false || ($circleId !== null && $c->hiddenCircle($circleId))) {
				$c->policy->block($c->vector, $c->actor, $circleId ?: null);
				if ($c->policy->enforcing()) {
					throw new ShortCircuit(new DataResponse([], Http::STATUS_NOT_FOUND));
				}
			}
			return null;
		}]);
		$add('OCA\Circles\Controller\DeprecatedController', ['listing'], ['after' => fn (K $c, Response $r) => $this->mapData($r, function ($d) use ($c) {
			if (isset($d['data']) && is_array($d['data'])) {
				$d['data'] = $c->filterList($d['data'], fn ($e) => $this->circleSearchHidden($c, $e));
			}
			return $d;
		})]);

		// ----------------------------------------------------------- formulaires
		$add('OCA\Forms\Controller\ShareApiController', ['newShare'], [
			'before' => fn (K $c) => $this->shareTarget($c, 'shareType', 'shareWith'),
		]);

		$add('OCA\Forms\Controller\ApiController', ['updateForm'], ['before' => function (K $c) {
			$kv = $c->request->getParam('keyValuePairs');
			if (is_array($kv) && isset($kv['ownerId']) && is_string($kv['ownerId']) && $c->hiddenUser($kv['ownerId'])) {
				$kv['ownerId'] = $c->ghostUid($kv['ownerId']);
				return ['keyValuePairs' => $kv];
			}
			return null;
		}]);

		// ------------------------------------------- propositions de réunion
		$add('OCA\Calendar\Controller\ProposalController', ['create', 'modify'], ['before' => function (K $c) {
			$p = $c->request->getParam('proposal');
			if (!is_array($p) || !isset($p['participants']) || !is_array($p['participants'])) {
				return null;
			}
			$changed = false;
			foreach ($p['participants'] as $i => $part) {
				$addr = (string)($part['address'] ?? '');
				$mail = preg_replace('/^mailto:/i', '', $addr);
				// participant interne d'une autre cloison : traité comme une adresse externe (e-mail seulement)
				if (($part['realm'] ?? 'E') === 'I' && $mail !== '' && $c->hiddenEmail($mail)) {
					$p['participants'][$i]['realm'] = 'E';
					$c->policy->block($c->vector . ':internal-participant', $c->actor, $mail);
					$changed = true;
				}
			}
			return $changed ? ['proposal' => $p] : null;
		}]);

		// ---------------------------------------------------------- e2ee
		$add('OCA\EndToEndEncryption\Controller\KeyController', ['getPublicKeys'], ['params' => ['users' => K::KIND_UIDS]]);

		// ---------------------------------------------------------- onlyoffice
		$oo = 'OCA\Onlyoffice\Controller\EditorController';
		$add($oo, ['userInfo'], ['params' => ['userIds' => K::KIND_UIDS]]);
		$add($oo, ['mention'], ['params' => ['emails' => K::KIND_EMAILS]]);
		$add($oo, ['users', 'emails'], ['after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
			? $c->filterList($d, fn ($e) => $this->entryHidden($c, $e)) : $d)]);

		// ---------------------------------------------------------- libresign
		$add('OCA\Libresign\Controller\IdentifyController', ['search'], ['after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
			? $c->filterList($d, fn ($e) => $this->entryHidden($c, $e)) : $d)]);
		$add('OCA\Libresign\Controller\RequestSignatureController', ['requestSignature', 'updateSignatureRequest'], [
			'before' => fn (K $c) => $this->libresignSigners($c),
		]);

		// relance d'un signataire neutralisé : réponse native, sans courriel
		$ls = 'OCA\Libresign\Controller\NotifyController';
		$sent = fn () => new DataResponse(['message' => \OCP\Util::getL10N('libresign')->t('Notification sent with success.')]);
		$add($ls, ['signer'], ['before' => function (K $c) use ($sent) {
			$id = (int)$c->request->getParam('signRequestId', 0);
			if ($id > 0 && $this->signRequestIsGhost($id) && $c->policy->enforcing()) {
				throw new ShortCircuit($sent());
			}
			return null;
		}]);
		$add($ls, ['signers'], ['before' => function (K $c) use ($sent) {
			$signers = $c->request->getParam('signers');
			if (!is_array($signers)) {
				return null;
			}
			$kept = array_values(array_filter($signers, fn ($sg) => !str_contains(json_encode($sg) ?: '', ZoneService::GHOST_PREFIX)
				&& !(is_array($sg) && isset($sg['email']) && is_string($sg['email']) && $c->hiddenEmail($sg['email']))));
			if (count($kept) === count($signers) || !$c->policy->enforcing()) {
				return null;
			}
			if ($kept === []) {
				throw new ShortCircuit($sent());
			}
			return ['signers' => $kept];
		}]);

		// ---------------------------------------------------------- activity
		// Objet enrichi « compte » d'une autre cloison (mention dans un commentaire…) :
		// nom remplacé par l'identifiant, comme Activity le fait pour un compte inexistant
		$act = fn (K $c, Response $r) => $this->mapData($r, fn ($d) => $this->maskRichUsers($c, $d));
		// Activity 8 (Nextcloud 35) : filtre « actor » et histogramme ; un compte
		// caché y est remplacé par un fantôme (même résultat qu'un compte inexistant).
		// Paramètre absent en Activity 7 : la règle n'y change rien.
		$add('OCA\Activity\Controller\APIv2Controller', ['getDefault', 'getFilter'], ['after' => $act, 'params' => ['actor' => K::KIND_UID]]);
		$add('OCA\Activity\Controller\APIv2Controller', ['getHistogram'], ['params' => ['actor' => K::KIND_UID], 'since' => 35]);
		$add('OCA\Activity\Controller\APIv1Controller', ['get'], ['after' => $act]);

		// --------------------------------------------------------- bookmarks
		// Partage d'un dossier de favoris : mêmes règles que les autres partages.
		// (Application réservée au groupe admin ; défense en profondeur.)
		// deux points d'entrée : API REST publique (FoldersController) et interface web
		// (InternalFoldersController, qui appelle le premier sans repasser par le middleware)
		$bookmarkShare = ['before' => function (K $c) {
			$with = (string)$c->request->getParam('participant', '');
			$type = (int)$c->request->getParam('type', 0);
			if ($with === '') {
				return null;
			}
			// Bookmarks partage même vers un compte inexistant (lignes créées en base) :
			// pas de fantôme ici, refus net sans rien écrire. Application réservée aux
			// administrateurs (qui voient déjà tous les comptes) : aucun oracle utile.
			$hidden = match ($type) {
				0 => $c->hiddenUser($with),
				1 => $c->hiddenGroup($with),
				7 => $c->hiddenCircle($with),
				default => false,
			};
			if ($hidden && $c->policy->block($c->vector, $c->actor, $with)) {
				throw new ShortCircuit(new DataResponse(['status' => 'error', 'data' => 'Cannot share with this participant'], 400));
			}
			return null;
		}];
		$add('OCA\Bookmarks\Controller\FoldersController', ['createShare'], $bookmarkShare);
		$add('OCA\Bookmarks\Controller\InternalFoldersController', ['createShare'], $bookmarkShare);

		// --------------------------------------------------------------- text
		$add('OCA\Text\Controller\UserApiController', ['index'], ['anonymous' => true,
			'after' => fn (K $c, Response $r) => $this->mapData($r, fn ($d) => is_array($d)
				? $c->filterList($d, fn ($v, $k) => is_string($k) && $c->hiddenUser($k)) : $d)]);
		$add('OCA\Text\Controller\SessionController', ['mention'], ['params' => ['mention' => K::KIND_UID]]);

		return $t;
	}

	// ================================================================ helpers

	/** Objets enrichis {type: user, id, name} d'un compte caché : name = id (rendu natif d'un compte inexistant). */
	private function maskRichUsers(K $c, mixed $d): mixed {
		if (!is_array($d)) {
			return $d;
		}
		if (($d['type'] ?? null) === 'user' && isset($d['id'], $d['name']) && is_string($d['id']) && !isset($d['server'])
			&& $c->hiddenUser($d['id'])) {
			$d['name'] = $d['id'];
			$c->policy->block($c->vector . ':rich-user', $c->actor, $d['id']);
			return $d;
		}
		foreach ($d as $k => $v) {
			if (is_array($v)) {
				$d[$k] = $this->maskRichUsers($c, $v);
			}
		}
		return $d;
	}

	private function mapData(Response $r, callable $fn): Response {
		if (ResponseData::has($r)) {
			ResponseData::set($r, $fn(ResponseData::get($r)));
		}
		return $r;
	}

	/** Paramètres d'un partage (fichiers, formulaires) selon son type. */
	private function shareTarget(K $c, string $typeParam, string $withParam): ?array {
		$type = $c->request->getParam($typeParam);
		$with = $c->request->getParam($withParam);
		if ($type === null || !is_string($with) || $with === '') {
			return null;
		}
		switch ((int)$type) {
			case 0:
				return $c->hiddenUser($with) ? [$withParam => $c->ghostUid($with)] : null;
			case 1:
				return $c->hiddenGroup($with) ? [$withParam => $c->ghostGroup($with)] : null;
			case 6:
			case 9:
				if ($c->targets()->isLocalCloudId($with)) {
					// réponse native du partage (« Failed to create share. »), sans l'erreur journalisée
					$c->policy->block($c->vector . ':federated-local', $c->actor, $with);
					if ($c->policy->enforcing()) {
						throw new OCSForbiddenException(\OCP\Util::getL10N('files_sharing')->t('Failed to create share.'));
					}
				}
				return null;
			case 7:
				return $c->hiddenCircle($with) ? [$withParam => $c->ghostCircle($with)] : null;
		}
		return null;
	}

	/** Résultat de recherche ou contact : désigne-t-il un compte caché ? */
	public function entryHidden(K $c, mixed $e): bool {
		if (!is_array($e)) {
			return false;
		}
		$json = json_encode($e) ?: '';
		foreach ($this->uidRefs($json) as $uid) {
			if ($c->hiddenUser($uid)) {
				return true;
			}
		}
		foreach ($this->emailRefs($json) as $mail) {
			if ($c->hiddenEmail($mail)) {
				return true;
			}
		}
		foreach (['uid', 'userId', 'user', 'id', 'userid'] as $k) {
			if (isset($e[$k]) && is_string($e[$k]) && $c->hiddenUser($e[$k])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Entrée de contact : cachée seulement si elle provient d'un compte d'une
	 * autre cloison (carte du carnet système, principal, profil, avatar). Un
	 * contact personnel du compte reste visible, même avec l'adresse d'un compte
	 * d'une autre cloison : c'est sa propre donnée.
	 */
	public function contactEntryHidden(K $c, mixed $e): bool {
		if (!is_array($e)) {
			return false;
		}
		foreach ($this->uidRefs(json_encode($e) ?: '') as $uid) {
			if ($c->hiddenUser($uid)) {
				return true;
			}
		}
		$system = ($e['source'] ?? null) === 'system' || ($e['isUser'] ?? false) === true
			|| str_contains((string)($e['uuid'] ?? ''), 'Database:') || isset($e['userId']);
		if ($system) {
			foreach (['userId', 'uid', 'id'] as $k) {
				if (isset($e[$k]) && is_string($e[$k]) && $c->hiddenUser($e[$k])) {
					return true;
				}
			}
			foreach ($this->emailRefs(json_encode($e) ?: '') as $mail) {
				if ($c->hiddenEmail($mail)) {
					return true;
				}
			}
		}
		return false;
	}

	/** uids cités par les URL et identifiants courants de Nextcloud. */
	private function uidRefs(string $json): array {
		$s = stripslashes($json);
		$out = [];
		foreach ([
			'#/avatar/([^/"?]+)/#',
			'#/u/([^/"?]+)#',
			'#Database:([^".]+)\.vcf#',
			'#principals/users/([^/"]+)#',
			'#callUser=([^&"\#]+)#',
		] as $re) {
			if (preg_match_all($re, $s, $m)) {
				foreach ($m[1] as $u) {
					$out[] = rawurldecode($u);
				}
			}
		}
		return array_unique($out);
	}

	private function emailRefs(string $json): array {
		if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', stripslashes($json), $m)) {
			return array_unique($m[0]);
		}
		return [];
	}

	private function autocompleteHidden(K $c, mixed $e): bool {
		if (!is_array($e)) {
			return false;
		}
		$id = (string)($e['id'] ?? '');
		switch ($e['source'] ?? '') {
			case 'users':
				return $c->hiddenUser($id);
			case 'groups':
				return $c->hiddenGroup($id);
			case 'emails':
				return $c->hiddenEmail($id);
			case 'circles':
			case 'teams':
				return $c->hiddenCircle($id);
			case 'remotes':
			case 'federated_users':
				return $c->hiddenCloudId($id);
		}
		return $this->entryHidden($c, $e);
	}

	private function filterSharees(K $c, mixed $d): mixed {
		if (!is_array($d)) {
			return $d;
		}
		$term = (string)$c->request->getParam('search', '');
		$filter = function (array $list, string $type) use ($c, $term): array {
			$out = [];
			foreach ($list as $e) {
				$with = (string)($e['value']['shareWith'] ?? '');
				$hidden = match ($type) {
					'users' => $c->hiddenUser($with),
					'groups' => $c->hiddenGroup($with),
					'remotes', 'remote_groups' => $c->hiddenCloudId($with) || $this->entryHidden($c, $e),
					'circles' => $c->hiddenCircle($with),
					'emails' => $this->contactEntryHidden($c, $e),
					'lookup' => true,
					default => $this->entryHidden($c, $e),
				};
				if (!$hidden) {
					$out[] = $e;
					continue;
				}
				$c->policy->block($c->vector . ':' . $type, $c->actor, $with);
				// Adresse saisie en entier : même suggestion que pour une adresse inconnue
				if ($type === 'emails' && strcasecmp($with, $term) === 0) {
					$out[] = ['label' => $term, 'uuid' => $term, 'value' => ['shareType' => 4, 'shareWith' => $term]];
				}
			}
			return $out;
		};
		foreach (['users', 'groups', 'remotes', 'remote_groups', 'emails', 'circles', 'rooms', 'lookup'] as $type) {
			if (isset($d[$type]) && is_array($d[$type])) {
				$d[$type] = $filter($d[$type], $type);
			}
			if (isset($d['exact'][$type]) && is_array($d['exact'][$type])) {
				$d['exact'][$type] = $filter($d['exact'][$type], $type);
			}
		}
		return $d;
	}

	private function talkCreate(K $c): ?array {
		$out = [];
		$type = (int)$c->request->getParam('roomType', 0);
		$invite = $c->request->getParam('invite');
		$source = (string)$c->request->getParam('source', '');
		if (is_string($invite) && $invite !== '') {
			if ($type === 1) {
				if ($c->hiddenUser($invite)) {
					$out['invite'] = $c->ghostUid($invite);
				}
			} elseif ($source === 'circles') {
				if ($c->hiddenCircle($invite)) {
					$out['invite'] = $c->ghostCircle($invite);
				}
			} elseif ($c->hiddenGroup($invite)) {
				$out['invite'] = $c->ghostGroup($invite);
			}
		}
		$participants = $c->request->getParam('participants');
		if (is_array($participants)) {
			$changed = false;
			foreach ($participants as $kind => $list) {
				if (!is_array($list)) {
					continue;
				}
				foreach ($list as $i => $v) {
					if (!is_string($v)) {
						continue;
					}
					$sub = match ($kind) {
						'users' => $c->hiddenUser($v) ? $c->ghostUid($v) : null,
						'groups' => $c->hiddenGroup($v) ? $c->ghostGroup($v) : null,
						'teams', 'circles' => $c->hiddenCircle($v) ? $c->ghostCircle($v) : null,
						'federated_users' => $c->targets()->isLocalCloudId($v) ? $c->ghostCloudId($v) : null,
						'emails' => $c->hiddenEmail($v) ? '' : null,
						default => null,
					};
					if ($sub === '') {
						// adresse d'un compte d'une autre cloison : retirée, sans courriel
						unset($participants[$kind][$i]);
						$changed = true;
					} elseif ($sub !== null) {
						$participants[$kind][$i] = $sub;
						$changed = true;
					}
				}
			}
			if ($changed) {
				foreach ($participants as $k => $list) {
					if (is_array($list)) {
						$participants[$k] = array_values($list);
					}
				}
				$out['participants'] = $participants;
			}
		}
		return $out ?: null;
	}

	private function talkParticipant(K $c, string $sourceParam, string $valueParam): ?array {
		$v = $c->request->getParam($valueParam);
		if (!is_string($v) || $v === '') {
			return null;
		}
		$source = (string)$c->request->getParam($sourceParam, 'users');
		if ($source === 'emails' && $c->hiddenEmail($v)) {
			// invitation par e-mail de l'adresse d'un compte d'une autre cloison :
			// réponse native de Talk (200, vide) sans invité ni courriel (aucun échec SMTP)
			$c->policy->block($c->vector . ':email', $c->actor, $v);
			if ($c->policy->enforcing()) {
				throw new ShortCircuit(new DataResponse([]));
			}
			return null;
		}
		if ($source === 'federated_users' && $c->targets()->isLocalCloudId($v)) {
			// réponse native de Talk (fédération impossible), sans l'avertissement journalisé
			$c->policy->block($c->vector . ':federated-local', $c->actor, $v);
			if ($c->policy->enforcing()) {
				throw new ShortCircuit(new DataResponse(['error' => 'federation'], 501));
			}
			return null;
		}
		$sub = match ($source) {
			'users' => $c->hiddenUser($v) ? $c->ghostUid($v) : null,
			'groups' => $c->hiddenGroup($v) ? $c->ghostGroup($v) : null,
			'circles', 'teams' => $c->hiddenCircle($v) ? $c->ghostCircle($v) : null,
			'emails' => $c->hiddenEmail($v) ? $c->ghostEmail($v) : null,
			default => null,
		};
		return $sub === null ? null : [$valueParam => $sub];
	}

	private function circleMember(K $c, string $idParam, string $typeParam): ?array {
		$id = (string)$c->request->getParam($idParam, '');
		$type = (int)$c->request->getParam($typeParam, 1);
		// réponses natives de Teams pour une cible inexistante, sans ses erreurs journalisées
		if ($type === 1 && $c->hiddenUser($id) && $c->policy->block($c->vector, $c->actor, $id)) {
			throw new OCSNotFoundException('user ' . $id . ' not found');
		}
		if ($type === 2 && $c->hiddenGroup($id) && $c->policy->block($c->vector . ':group', $c->actor, $id)) {
			throw new OCSNotFoundException('group not found');
		}
		$sub = $this->circleMemberValue($c, $id, $type);
		return $sub === null ? null : [$idParam => $sub];
	}

	/** Membre d'équipe : 1 compte, 2 groupe, 4 e-mail, 8 contact, 16 équipe. */
	private function circleMemberValue(K $c, string $id, int $type): ?string {
		if ($id === '') {
			return null;
		}
		return match ($type) {
			1 => $c->hiddenUser($id) ? $c->ghostUid($id) : ($c->hiddenCloudId($id) ? $c->ghostCloudId($id) : null),
			2 => $c->hiddenGroup($id) ? $c->ghostGroup($id) : null,
			4 => $c->hiddenEmail($id) ? $c->ghostEmail($id) : null,
			16 => $c->hiddenCircle($id) ? $c->ghostCircle($id) : null,
			default => null,
		};
	}

	private function circleSearchHidden(K $c, mixed $e): bool {
		if (!is_array($e)) {
			return false;
		}
		$id = (string)($e['userId'] ?? $e['id'] ?? $e['ident'] ?? '');
		$type = (int)($e['userType'] ?? $e['type'] ?? 1);
		return match ($type) {
			1 => $c->hiddenUser($id) || $this->entryHidden($c, $e),
			2 => $c->hiddenGroup($id),
			16 => $c->hiddenCircle((string)($e['singleId'] ?? $id)),
			default => $this->entryHidden($c, $e),
		};
	}

	private function actorInCircle(K $c, array $circle): bool {
		$members = $c->targets()->circleUserIds((string)$circle['id']);
		return $members !== null && in_array($c->actor, $members, true);
	}

	/**
	 * Signataires LibreSign : un compte d'une autre cloison, ou l'adresse e-mail
	 * d'un tel compte (LibreSign rattache l'adresse au compte et lui demande de
	 * se connecter), est remplacé par le fantôme.
	 */
	private function libresignSigners(K $c): ?array {
		$signers = $c->request->getParam('signers');
		if (!is_array($signers)) {
			return null;
		}
		$changed = false;
		$fix = function (string $method, string $value) use ($c): ?string {
			return match ($method) {
				'account' => $c->hiddenUser($value) ? $c->ghostUid($value) : ($c->hiddenEmail($value) ? $c->ghostEmail($value) : null),
				'email' => $c->hiddenEmail($value) ? $c->ghostEmail($value) : null,
				default => null,
			};
		};
		foreach ($signers as $i => $s) {
			if (!is_array($s)) {
				continue;
			}
			foreach (['identify'] as $legacy) {
				if (isset($s[$legacy]) && is_array($s[$legacy])) {
					foreach ($s[$legacy] as $method => $value) {
						if (is_string($value) && ($sub = $fix((string)$method, $value)) !== null) {
							$signers[$i][$legacy][$method] = $sub;
							$signers[$i]['notify'] = 0;
							$changed = true;
						}
					}
				}
			}
			if (isset($s['identifyMethods']) && is_array($s['identifyMethods'])) {
				foreach ($s['identifyMethods'] as $j => $im) {
					$method = (string)($im['method'] ?? '');
					$value = $im['value'] ?? null;
					if (is_string($value) && ($sub = $fix($method, $value)) !== null) {
						$signers[$i]['identifyMethods'][$j]['value'] = $sub;
						$signers[$i]['notify'] = 0; // aucun courriel vers l'adresse fantôme
						$changed = true;
					}
				}
			}
		}
		return $changed ? ['signers' => $signers] : null;
	}

	/**
	 * Équipe désignée par un code d'invitation (Circles 35+) : null si le code est
	 * inconnu, false si la recherche a échoué (le lien est alors refusé).
	 * Lecture directe de la table : la sonde de Circles ignore silencieusement le
	 * filtre par code si sa jointure échoue et renverrait une équipe quelconque.
	 */
	private function circleByInvitation(string $code): string|false|null {
		try {
			$db = Server::get(\OCP\IDBConnection::class);
			if (!$db->tableExists('circles_invitations')) {
				return null;
			}
			$qb = $db->getQueryBuilder();
			$qb->select('circle_id')->from('circles_invitations')
				->where($qb->expr()->eq('invitation_code', $qb->createNamedParameter($code)))
				->setMaxResults(1);
			$res = $qb->executeQuery();
			$id = $res->fetchOne();
			$res->closeCursor();
			return $id === false || $id === null || $id === '' ? null : (string)$id;
		} catch (\Throwable $e) {
			Server::get(Policy::class)->graveOnce('circles-invitation', 'Cloisons : code d\'invitation d\'équipe impossible à vérifier, lien refusé', $e);
			return false;
		}
	}

	private function signRequestIsGhost(int $signRequestId): bool {
		$db = Server::get(\OCP\IDBConnection::class);
		if (!$db->tableExists('libresign_identify_method')) {
			return false;
		}
		$qb = $db->getQueryBuilder();
		$qb->select('identifier_value')->from('libresign_identify_method')
			->where($qb->expr()->eq('sign_request_id', $qb->createNamedParameter($signRequestId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('identifier_value', $qb->createNamedParameter(ZoneService::GHOST_PREFIX . '%')));
		return $qb->executeQuery()->fetchOne() !== false;
	}

	public function userExists(string $uid): bool {
		return Server::get(IUserManager::class)->userExists($uid);
	}
}
