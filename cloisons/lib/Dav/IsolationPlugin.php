<?php

declare(strict_types=1);

namespace OCA\Cloisons\Dav;

use OCA\Cloisons\Service\Policy;
use OCA\Cloisons\Service\Targets;
use OCA\Cloisons\Service\ZoneService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\ITip\Message;

/**
 * Cloisonnement WebDAV / CalDAV / CardDAV.
 *
 *  - chemins d'un compte caché (fichiers, agendas, carnets, principal…) :
 *    réécrits vers un compte fantôme, la réponse est celle d'un compte inexistant ;
 *  - recherches de principaux, carnet système, membres de groupes, invités
 *    d'un partage : les entrées cachées sont retirées des réponses ;
 *  - partage d'agenda/carnet, délégation, disponibilités : les cibles cachées
 *    sont remplacées par le fantôme ;
 *  - invitations d'agenda : jamais livrées en interne hors cloison, elles
 *    partent par e-mail comme vers une adresse externe.
 */
class IsolationPlugin extends ServerPlugin {
	private ?Server $server = null;
	/** @var array<string, string> */
	private array $echo = [];
	private ?string $actor = null;

	private const HOME_PATTERNS = [
		'#^(principals/users/)([^/]+)(/.*)?$#',
		'#^(calendars/)([^/]+)(/.*)?$#',
		'#^(addressbooks/users/)([^/]+)(/.*)?$#',
		'#^(files/)([^/]+)(/.*)?$#',
		'#^(uploads/)([^/]+)(/.*)?$#',
		'#^(trashbin/)([^/]+)(/.*)?$#',
		'#^(versions/)([^/]+)(/.*)?$#',
		'#^(avatars/)([^/]+)(/.*)?$#',
		'#^(principals/calendar-proxy-read/)([^/]+)(/.*)?$#',
	];

	public function __construct(
		private ZoneService $zones,
		private Policy $policy,
		private Targets $targets,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
	) {
	}

	public function getPluginName(): string {
		return 'cloisons';
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		// juste après l'authentification Sabre (10) et avant les droits ACL (20)
		$server->on('beforeMethod:*', [$this, 'beforeMethod'], 15);
		$server->on('afterMethod:*', [$this, 'afterMethod'], 90); // avant la compression gzip (100)
		$server->on('schedule', [$this, 'schedule'], 90);
		if (get_class($server->sapi) === \Sabre\HTTP\Sapi::class) {
			RestoringSapi::attach($this);
			$server->sapi = new RestoringSapi();
		}
	}

	/** Dernier passage avant envoi (succès comme erreurs). */
	public function finalize(ResponseInterface $response): void {
		$body = $response->getBody();
		if (is_resource($body)) {
			return; // contenu de fichier : jamais modifié
		}
		$text = is_string($body) ? $body : $response->getBodyAsString();
		$new = $this->echo === [] ? $text : strtr($text, $this->echo);
		$new = $this->zones->restoreGhosts($new, $this->actor());
		if ($new !== $text || !is_string($body)) {
			$response->setBody($new);
			$response->removeHeader('Content-Length');
		}
	}

	private function actor(): ?string {
		// pas de mise en cache d'un « anonyme » : l'authentification peut intervenir plus tard
		return $this->actor ??= $this->zones->currentUid();
	}

	private function hiddenUser(string $uid): bool {
		$a = $this->actor();
		return $a !== null && $uid !== '' && !$this->zones->canSee($a, $uid) && $this->userManager->userExists($uid);
	}

	private function hiddenGroup(string $gid): bool {
		$a = $this->actor();
		return $a !== null && $this->groupManager->groupExists($gid) && !$this->zones->isGroupWithinZone($a, $gid);
	}

	private function hiddenEmail(string $email): bool {
		$a = $this->actor();
		if ($a === null) {
			return false;
		}
		foreach ($this->targets->usersByEmail($email) as $uid) {
			if (!$this->zones->canSee($a, $uid)) {
				return true;
			}
		}
		return false;
	}

	private function ghost(string $original, string $vector): string {
		$kind = in_array($vector, ['group-principal', 'group-ref'], true) ? 'group' : 'uid';
		$g = $this->zones->ghostFor($original, $kind, $this->actor());
		$this->echo[$g] = $original;
		$this->policy->block('dav:' . $vector, $this->actor(), $original);
		return $g;
	}

	private function ghostMail(string $original, string $vector): string {
		$g = $this->zones->ghostFor($original, 'email', $this->actor());
		$this->echo[$g] = $original;
		$this->policy->block('dav:' . $vector, $this->actor(), $original);
		return $g;
	}

	// ------------------------------------------------------------ requête

	public function beforeMethod(RequestInterface $request, ResponseInterface $response) {
		$this->echo = [];
		if ($this->actor() === null) {
			return;
		}
		\OCP\Server::get(\OCA\Cloisons\Contacts\ContactsGuard::class)->install();
		$path = $request->getPath();
		// WebDAV public (lien de partage) : même règle que les pages publiques
		if (str_contains($this->server->getBaseUri(), 'public.php') && preg_match('#^files/([^/]+)(/.*)?$#', trim($path, '/'), $pm)) {
			$guard = \OCP\Server::get(\OCA\Cloisons\Middleware\PublicGuard::class);
			$read = in_array($request->getMethod(), ['GET', 'HEAD', 'PROPFIND', 'OPTIONS', 'REPORT'], true);
			if ($guard->fileShareBlocked((string)$this->actor(), rawurldecode($pm[1]), $read)
				&& $this->policy->block('public-link:dav', $this->actor(), $pm[1])) {
				$ghost = $guard->ghostFileToken(rawurldecode($pm[1]));
				$this->echo[$ghost] = rawurldecode($pm[1]);
				$request->setUrl($this->server->getBaseUri() . 'files/' . $ghost . ($pm[2] ?? ''));
			}
			return;
		}
		// NB : public.php (liens publics WebDAV) force le mode anonyme de Nextcloud
		// (OC_User::setIncognitoMode) : un compte connecté y est un invité anonyme,
		// comme sur une instance dédiée ; aucune identité n'y est exposée.
		$newPath = $this->rewritePath($path);
		if ($newPath !== $path && $this->policy->enforcing()) {
			$base = $this->server->getBaseUri();
			$request->setUrl($base . implode('/', array_map('rawurlencode', explode('/', $newPath))));
		}

		$method = $request->getMethod();
		if (in_array($method, ['POST', 'PROPPATCH', 'REPORT', 'PROPFIND'], true)) {
			$ctype = (string)$request->getHeader('Content-Type');
			if ($method === 'POST' && !str_contains($ctype, 'xml') && !str_contains($ctype, 'calendar')) {
				return;
			}
			$body = $request->getBodyAsString();
			$request->setBody($body); // le flux a été consommé
			if ($body === '') {
				return;
			}
			// délégation d'agenda vers un compte caché : réponse native (« Principal not
			// found », 500) rendue directement, sans l'erreur que Sabre journaliserait
			if ($method === 'PROPPATCH' && preg_match('#/calendar-proxy-(read|write)/?$#', $request->getPath())
				&& preg_match_all('#principals/users/([^/<"\s&]+)#', $this->decodeBody($body), $mm)) {
				foreach ($mm[1] as $u) {
					$u = rawurldecode($u);
					if ($this->hiddenUser($u) && $this->policy->block('dav:delegation', $this->actor(), $u)) {
						$response->setStatus(500);
						$response->setHeader('Content-Type', 'application/xml; charset=utf-8');
						$response->setBody("<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<d:error xmlns:d=\"DAV:\" xmlns:s=\"http://sabredav.org/ns\">\n  <s:exception>Sabre\\DAV\\Exception</s:exception>\n  <s:message>Principal not found</s:message>\n</d:error>\n");
						$this->server->sapi->sendResponse($response);
						return false;
					}
				}
			}
			$new = $this->sanitizeBody($body);
			if ($new !== $body && $this->policy->enforcing()) {
				$request->setBody($new);
			}
		}
	}

	private function rewritePath(string $path): string {
		$path = trim($path, '/');
		// agenda public (lien de publication) d'une autre cloison, ouvert par un
		// compte connecté : même règle que la page publique (réponse « inexistant »)
		if (preg_match('#^public-calendars/([^/]+)(/.*)?$#', $path, $pc)) {
			$tok = rawurldecode($pc[1]);
			$guard = \OCP\Server::get(\OCA\Cloisons\Middleware\PublicGuard::class);
			if ($guard->calendarBlocked((string)$this->actor(), $tok)
				&& $this->policy->block('public-link:calendar-dav', $this->actor(), $tok)) {
				$ghost = $guard->ghostCalendarToken($tok);
				$this->echo[$ghost] = $tok;
				return 'public-calendars/' . $ghost . ($pc[2] ?? '');
			}
			return $path;
		}
		foreach (self::HOME_PATTERNS as $re) {
			if (preg_match($re, $path, $m)) {
				$owner = rawurldecode($m[2]);
				if ($this->hiddenUser($owner)) {
					return $m[1] . $this->ghost($owner, 'path') . ($m[3] ?? '');
				}
				// carte du carnet système désignant un compte caché
				if (preg_match('#/(?:z-server-generated--system|system)/Database:([^/]+)\.vcf$#', $path, $c)
					&& $this->hiddenUser(rawurldecode($c[1]))) {
					return (string)preg_replace('#Database:[^/]+\.vcf$#', 'Database:' . $this->ghost(rawurldecode($c[1]), 'card') . '.vcf', $path);
				}
				return $path;
			}
		}
		if (preg_match('#^addressbooks/system/system/system/Database:([^/]+)\.vcf$#', $path, $c)
			&& $this->hiddenUser(rawurldecode($c[1]))) {
			return 'addressbooks/system/system/system/Database:' . $this->ghost(rawurldecode($c[1]), 'card') . '.vcf';
		}
		if (preg_match('#^principals/groups/([^/]+)(/.*)?$#', $path, $g)) {
			$gid = rawurldecode($g[1]);
			if ($this->hiddenGroup($gid)) {
				return 'principals/groups/' . $this->ghost($gid, 'group-principal') . ($g[2] ?? '');
			}
		}
		return $path;
	}

	/**
	 * Cibles cachées (principaux, e-mails) dans un corps de partage, délégation
	 * ou disponibilité.
	 *
	 * Le corps est d'abord NORMALISÉ pour déjouer les écritures détournées :
	 *  - entités XML numériques et hexadécimales décodées (`&#110;` → « n ») ;
	 *  - sections CDATA dépliées ;
	 *  - repli de ligne iCalendar retiré (une valeur coupée par `\r\n `).
	 * Les motifs sont ensuite cherchés sur ce texte décodé, de sorte qu'aucune
	 * représentation alternative d'un identifiant ou d'une adresse n'échappe au
	 * filtrage. En cas de doute (corps qui change après normalisation mais dont
	 * on ne peut pas réécrire proprement l'original), la requête est refusée.
	 */
	private function sanitizeBody(string $body): string {
		// Une DTD n'est envoyée par aucun client CalDAV/CardDAV légitime et permet
		// de définir des entités : refus systématique (indépendant de la cible,
		// donc sans oracle).
		if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
			$this->policy->block('dav:dtd', $this->actor(), null);
			throw new \Sabre\DAV\Exception\BadRequest('DTD not allowed');
		}
		$decoded = $this->decodeBody($body);
		// cibles cachées détectées sur le texte décodé
		$hidden = $this->hiddenTargetsIn($decoded);
		if ($hidden === []) {
			return $body;
		}
		$rewrite = function (string $text): string {
			$text = (string)preg_replace_callback('#principals/users/([^/<"\s&]+)#', function ($m) {
				$uid = rawurldecode($m[1]);
				return $this->hiddenUser($uid) ? 'principals/users/' . $this->ghost($uid, 'principal-ref') : $m[0];
			}, $text);
			$text = (string)preg_replace_callback('#principals/groups/([^/<"\s&]+)#', function ($m) {
				$gid = rawurldecode($m[1]);
				return $this->hiddenGroup($gid) ? 'principals/groups/' . $this->ghost($gid, 'group-ref') : $m[0];
			}, $text);
			$text = (string)preg_replace_callback('#mailto:([^\s<>"\r\n;:&]+@[^\s<>"\r\n;:&]+)#i', function ($m) {
				return $this->hiddenEmail($m[1]) ? 'mailto:' . $this->ghostMail($m[1], 'mailto-ref') : $m[0];
			}, $text);
			return $text;
		};
		$trim = ltrim($body);
		if (str_starts_with($trim, '<')) {
			// XML : lu comme un vrai document (entités et CDATA décodés par
			// l'analyseur, exactement comme Sabre les verra), texte réécrit, puis
			// document resérialisé. Aucune écriture détournée n'échappe au filtrage.
			$dom = new \DOMDocument();
			$prev = libxml_use_internal_errors(true);
			$ok = $dom->loadXML($body, LIBXML_NONET);
			libxml_use_internal_errors($prev);
			if ($ok) {
				$changed = false;
				foreach ((new \DOMXPath($dom))->query('//text()') as $node) {
					$v = $node->nodeValue ?? '';
					$n = $rewrite($v);
					if ($n !== $v) {
						$node->nodeValue = $n;
						$changed = true;
					}
				}
				$out = $changed ? (string)$dom->saveXML() : $body;
			} else {
				$out = $rewrite($body); // XML invalide : Sabre le refusera de toute façon
			}
		} else {
			// iCalendar : lignes dépliées (forme équivalente), puis réécriture
			$out = $rewrite((string)preg_replace('/\r?\n[ \t]/', '', $body));
		}
		if ($this->hiddenTargetsIn($this->decodeBody($out)) !== []) {
			// ne devrait jamais arriver : on préfère refuser plutôt que laisser fuir
			$this->policy->graveOnce('dav-body', 'Cloisons : cible cachée non réécrite dans un corps DAV, requête refusée');
			throw new \Sabre\DAV\Exception\Forbidden('Target not found');
		}
		return $out;
	}

	/** Déplie entités XML, CDATA et repli de ligne iCalendar pour l'analyse. */
	private function decodeBody(string $body): string {
		$body = str_replace(['<![CDATA[', ']]>'], '', $body);
		$body = (string)preg_replace('/\r\n[ \t]/', '', $body); // repli iCalendar
		$body = (string)preg_replace_callback('/&#(\d+);/', fn ($m) => mb_chr((int)$m[1], 'UTF-8'), $body);
		$body = (string)preg_replace_callback('/&#x([0-9a-fA-F]+);/', fn ($m) => mb_chr((int)hexdec($m[1]), 'UTF-8'), $body);
		return $body;
	}

	/** @return list<string> cibles cachées (uid, gid, e-mail) présentes dans un texte décodé */
	private function hiddenTargetsIn(string $text): array {
		$found = [];
		if (preg_match_all('#principals/users/([^/<"\s&]+)#', $text, $m)) {
			foreach ($m[1] as $u) {
				if ($this->hiddenUser(rawurldecode($u))) {
					$found[] = 'u:' . rawurldecode($u);
				}
			}
		}
		if (preg_match_all('#principals/groups/([^/<"\s&]+)#', $text, $m)) {
			foreach ($m[1] as $g) {
				if ($this->hiddenGroup(rawurldecode($g))) {
					$found[] = 'g:' . rawurldecode($g);
				}
			}
		}
		if (preg_match_all('#mailto:([^\s<>"\r\n;:&]+@[^\s<>"\r\n;:&]+)#i', $text, $m)) {
			foreach ($m[1] as $e) {
				if ($this->hiddenEmail($e)) {
					$found[] = 'm:' . $e;
				}
			}
		}
		return array_values(array_unique($found));
	}

	// ------------------------------------------------------------ réponse

	public function afterMethod(RequestInterface $request, ResponseInterface $response) {
		if ($this->actor() === null) {
			return;
		}
		$status = $response->getStatus();
		$ctype = (string)$response->getHeader('Content-Type');
		$isXml = str_contains($ctype, 'xml');
		$isCal = str_contains($ctype, 'calendar');
		if (!$isXml && !$isCal && $this->echo === []) {
			return;
		}
		if (!$isXml && !$isCal) {
			return;
		}
		$body = $response->getBodyAsString();
		$new = $body;
		if ($isXml && $status === 207 && $this->policy->enforcing()) {
			$new = $this->filterMultistatus($new);
		}
		if ($this->echo !== []) {
			$new = strtr($new, $this->echo);
		}
		$new = $this->zones->restoreGhosts($new, $this->actor());
		$response->setBody($new);
		if ($new !== $body) {
			$response->removeHeader('Content-Length');
		}
	}

	private function refHidden(string $href): bool {
		if (preg_match('#principals/users/([^/]+)#', $href, $m) && $this->hiddenUser(rawurldecode($m[1]))) {
			return true;
		}
		if (preg_match('#principals/groups/([^/]+)#', $href, $m) && $this->hiddenGroup(rawurldecode($m[1]))) {
			return true;
		}
		if (preg_match('#Database:([^/]+)\.vcf#', $href, $m) && $this->hiddenUser(rawurldecode($m[1]))) {
			return true;
		}
		if (preg_match('#^mailto:(.+)$#i', trim($href), $m) && $this->hiddenEmail($m[1])) {
			return true;
		}
		return false;
	}

	private function filterMultistatus(string $xml): string {
		if (!str_contains($xml, 'principals/') && !str_contains($xml, 'Database:')
			&& !str_contains($xml, 'mailto:') && !str_contains($xml, 'mention')) {
			return $xml;
		}
		$dom = new \DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
		libxml_use_internal_errors($prev);
		if (!$ok) {
			return $xml;
		}
		$xp = new \DOMXPath($dom);
		$xp->registerNamespace('d', 'DAV:');
		$xp->registerNamespace('oc', 'http://owncloud.org/ns');
		$changed = false;
		// réponses entières désignant un compte caché
		foreach (iterator_to_array($xp->query('/d:multistatus/d:response')) as $resp) {
			$href = $xp->query('d:href', $resp)->item(0)?->textContent ?? '';
			if ($this->refHidden($href)) {
				$resp->parentNode->removeChild($resp);
				$this->policy->block('dav:multistatus', $this->actor(), $href);
				$changed = true;
				continue;
			}
			// carte vCard du carnet système désignant un compte caché
			foreach ($resp->getElementsByTagNameNS('urn:ietf:params:xml:ns:carddav', 'address-data') as $card) {
				if (preg_match('/^UID:(.+)$/m', $card->textContent, $u) && $this->hiddenUser(trim($u[1]))) {
					$resp->parentNode?->removeChild($resp);
					$changed = true;
					break;
				}
			}
		}
		// mentions de commentaire : nom affiché d'un compte caché masqué (comme un
		// compte inexistant : « Compte inconnu »), l'identifiant restant inchangé
		if (str_contains($xml, 'mention')) {
			$oc = 'http://owncloud.org/ns';
			foreach (iterator_to_array($xp->query('//oc:mention')) as $mention) {
				$type = $mention->getElementsByTagNameNS($oc, 'mentionType')->item(0)?->textContent ?? '';
				if ($type !== 'user' && $type !== 'federated_user') {
					continue;
				}
				$id = $mention->getElementsByTagNameNS($oc, 'mentionId')->item(0)?->textContent ?? '';
				$uid = $type === 'federated_user' ? ($this->targets->localUserFromCloudId($id) ?? '') : $id;
				if ($uid === '' || !$this->hiddenUser($uid)) {
					continue;
				}
				$dn = $mention->getElementsByTagNameNS($oc, 'mentionDisplayName')->item(0);
				if ($dn !== null) {
					$dn->textContent = \OCP\Server::get(\OCP\L10N\IFactory::class)->get('core')->t('Unknown account');
					$changed = true;
					$this->policy->block('dav:mention', $this->actor(), $uid);
				}
			}
		}

		// références internes (membres de groupe, invités d'un partage, délégués…)
		foreach (iterator_to_array($xp->query('//d:href')) as $h) {
			if ($h->parentNode === null || ($h->parentNode->localName === 'response')) {
				continue;
			}
			if ($this->refHidden($h->textContent)) {
				$parent = $h->parentNode;
				// <oc:user> / <cs:sharee> : retirer l'invité entier
				$container = in_array($parent->localName, ['user', 'sharee', 'invite-user'], true) ? $parent : $h;
				$container->parentNode?->removeChild($container);
				$changed = true;
			}
		}
		return $changed ? (string)$dom->saveXML() : $xml;
	}

	// ------------------------------------------------------- planification

	/**
	 * Invitation vers un compte d'une autre cloison : pas de livraison interne
	 * (agenda/boîte de réception), envoi par e-mail comme pour une adresse externe.
	 */
	public function schedule(Message $message) {
		$recipient = (string)$message->recipient;
		if (stripos($recipient, 'mailto:') !== 0) {
			return;
		}
		$sender = (string)$message->sender;
		$senderUid = null;
		if (stripos($sender, 'mailto:') === 0) {
			$users = $this->targets->usersByEmail(substr($sender, 7));
			$senderUid = $users[0] ?? null;
		}
		$senderUid ??= $this->actor();
		if ($senderUid === null) {
			return;
		}
		$hidden = false;
		foreach ($this->targets->usersByEmail(substr($recipient, 7)) as $uid) {
			if (!$this->zones->canSee($senderUid, $uid)) {
				$hidden = true;
			}
		}
		if (!$hidden || !$this->policy->block('dav:schedule', $senderUid, substr($recipient, 7))) {
			return;
		}
		$imip = $this->server?->getPlugin('imip');
		if ($imip !== null && method_exists($imip, 'schedule')) {
			$imip->schedule($message);
		} else {
			$message->scheduleStatus = '5.0; EMail delivery failed';
		}
		return false; // ni livraison interne, ni second envoi
	}
}
