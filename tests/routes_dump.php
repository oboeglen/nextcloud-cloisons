<?php
// Inventaire de toutes les routes (web + OCS) des applis actives — banc uniquement
require (getenv('NC_ROOT') ?: '/var/www/html') . '/lib/base.php';
\OC_App::loadApps();
$router = \OC::$server->get(\OCP\Route\IRouter::class);
$router->loadRoutes();
$out = [];
foreach ($router->getRouteCollection()->all() as $name => $r) {
    $d = $r->getDefaults();
    $out[] = ['name'=>$name,'path'=>$r->getPath(),'methods'=>implode(',', $r->getMethods() ?: ['ANY']),'ctrl'=>$d['caller'] ?? ($d['action'] ?? '')];
}
// routes OCS
$rc = new ReflectionClass($router);
foreach (['collections','root'] as $p) {}
if (method_exists($router, 'getCollections')) {}
echo json_encode($out, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
