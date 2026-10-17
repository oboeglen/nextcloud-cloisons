<?php
// Repère, pour chaque route accessible à un utilisateur non administrateur, les
// appels d'API susceptibles de toucher un autre compte (banc uniquement).
require (getenv('NC_ROOT') ?: '/var/www/html') . '/lib/base.php';
\OC_App::loadApps();
$routes = json_decode(file_get_contents('/tmp/routes_classified.json'), true);
$patterns = [
	'userManager' => '/userManager->(search|searchDisplayName|get|getByEmail|userExists|callForAllUsers|getDisplayNames|getSeenUsers)/i',
	'IUserManager' => '/IUserManager::class\)->(search|get|getByEmail)/i',
	'groupManager' => '/groupManager->(get|search|usersInGroup|displayNamesInGroup|getUserGroupIds|getUserGroups)\b/i',
	'shareCreate' => '/shareManager->(createShare|newShare)|->createShare\(/i',
	'notify' => '/notificationManager->notify|->notify\(\$notification|createNotification\(/i',
	'collabSearch' => '/collaboratorSearch->search|ISearch::class|->collaboratorSearch/i',
	'contacts' => '/contactsManager->search|IManager::class\)->search|contactsStore|->search\(\$.*\[.?EMAIL/i',
	'cloudId' => '/cloudIdManager|ICloudIdManager/i',
	'mailer' => '/mailer->send|IMailer/i',
	'principal' => '/principalBackend|Principal::class/i',
	'avatar' => '/avatarManager->getAvatar\(/i',
	'accountManager' => '/accountManager->getAccount\(/i',
	'userSessionOther' => '/->getUser\(\$/i',
];
$out = [];
foreach ($routes as $r) {
	if (!in_array($r['access'], ['user', 'public', 'subadmin', 'delegated'], true) || empty($r['class'])) continue;
	try {
		$m = new ReflectionMethod($r['class'], $r['ctrl'][2]);
	} catch (Throwable) { continue; }
	$file = $m->getFileName();
	$src = implode('', array_slice(file($file), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
	// suivre un niveau d'appels de services privés de la même classe
	if (preg_match_all('/\$this->([a-zA-Z_]+)\(/', $src, $mm)) {
		foreach (array_unique($mm[1]) as $sub) {
			if (method_exists($r['class'], $sub)) {
				$s = new ReflectionMethod($r['class'], $sub);
				if ($s->getFileName() === $file) {
					$src .= implode('', array_slice(file($file), $s->getStartLine() - 1, $s->getEndLine() - $s->getStartLine() + 1));
				}
			}
		}
	}
	$hits = [];
	foreach ($patterns as $k => $re) { if (preg_match($re, $src)) $hits[] = $k; }
	$out[] = ['app' => $r['ctrl'][0], 'route' => $r['methods'] . ' ' . $r['path'], 'method' => $r['class'] . '::' . $r['ctrl'][2], 'access' => $r['access'], 'params' => $r['params'], 'hits' => $hits];
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
