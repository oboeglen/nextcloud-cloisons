<?php
// Chaque règle de Rules.php doit viser une classe et une méthode existantes (banc)
require (getenv('NC_ROOT') ?: '/var/www/html') . '/lib/base.php';
\OC_App::loadApps();
$rules = \OCP\Server::get(\OCA\Cloisons\Service\Rules::class);
$bad = 0;
$major = \OCP\Server::get(\OCP\ServerVersion::class)->getMajorVersion();
foreach ($rules->certified() as $key) {
	if ($major < $rules->since($key)) { continue; } // route d'une version plus récente
	[$class, $method] = explode('::', $key);
	if (!class_exists($class)) { echo "CLASSE ABSENTE  $key\n"; $bad++; continue; }
	$ok = false;
	foreach ((new ReflectionClass($class))->getMethods() as $m) { if (strtolower($m->getName()) === $method) { $ok = true; } }
	if (!$ok) { echo "MÉTHODE ABSENTE $key\n"; $bad++; }
}
echo count($rules->certified()) . " règles, $bad erreur(s)\n";
exit($bad ? 1 : 0);
