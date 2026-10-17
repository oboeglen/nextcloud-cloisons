<?php
// Classe chaque route selon ses attributs d'accès — banc uniquement
register_shutdown_function(function(){ global $i; fwrite(STDERR, 'SHUTDOWN at '.($i??'-').' '.json_encode(error_get_last())."\n"); });
require (getenv('NC_ROOT') ?: '/var/www/html') . '/lib/base.php';
\OC_App::loadApps();
$routes = json_decode(file_get_contents('/tmp/routes.json'), true);
$res = [];
foreach ($routes as $i => $r) {
    $cc = is_array($r['ctrl']) ? $r['ctrl'] : []; [$app, $ctrl, $action] = $cc + [null, null, null];
    $flags = []; $class = null; $params = []; $file = 'UNRESOLVED';
    if (!$app) { $res[] = $r + ['class'=>null,'flags'=>[],'params'=>[],'access'=>'special','file'=>'UNRESOLVED']; continue; }
    try {
    if ($app === 'core') { $ns = 'OC\\Core\\Controller\\'; }
    else { $ns = \OC\AppFramework\App::buildAppNamespace($app) . '\\Controller\\'; }
    foreach ([$ns . $ctrl, $ns . $ctrl . 'Controller'] as $c) { if (class_exists($c)) { $class = $c; break; } }
    if ($class && method_exists($class, $action)) {
        $m = new ReflectionMethod($class, $action);
        foreach (array_merge($m->getAttributes(), (new ReflectionClass($class))->getAttributes()) as $a) {
            $flags[] = substr(strrchr('\\' . $a->getName(), '\\'), 1);
        }
        $doc = $m->getDocComment() ?: '';
        foreach (['NoAdminRequired','PublicPage','SubAdminRequired','NoCSRFRequired','AuthorizedAdminSetting','PasswordConfirmationRequired'] as $f) {
            if (stripos($doc, '@' . $f) !== false) $flags[] = $f;
        }
        $params = array_map(fn($p) => $p->getName(), $m->getParameters());
        $file = $m->getFileName() . ':' . $m->getStartLine();
    } else { $params = []; $file = 'UNRESOLVED'; }
    } catch (\Throwable $e) { fwrite(STDERR, $r['name'].' '.$e->getMessage()."\n"); }
    $flags = array_values(array_unique($flags));
    $access = in_array('PublicPage', $flags) ? 'public' : ((in_array('NoAdminRequired', $flags)) ? 'user' : (in_array('SubAdminRequired', $flags) ? 'subadmin' : (in_array('AuthorizedAdminSetting',$flags) ? 'delegated' : 'admin')));
    $res[] = $r + ['class' => $class, 'flags' => $flags, 'params' => $params, 'access' => $access, 'file' => $file];
}
$j = json_encode($res, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); fwrite(STDERR, json_last_error_msg()."\n"); echo $j;
