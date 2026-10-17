<?php
// usage: php index.php <out.json> <dir>...   indexe classes/méthodes (tokenizer, sans exécution)
$out = $argv[1]; $res = [];
function norm(string $s): string { return preg_replace('/\s+/', ' ', preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $s)); }
foreach (array_slice($argv, 2) as $dir) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$p = $f->getPathname();
		if (substr($p, -4) !== '.php' || preg_match('#/(vendor|tests?|3rdparty|node_modules|composer)/#', $p)) continue;
		$src = file_get_contents($p);
		try { $toks = PhpToken::tokenize($src); } catch (Throwable) { continue; }
		$ns = ''; $class = null; $depth = 0; $classDepth = -1; $pendingAttr = ''; $doc = '';
		$extends = '';
		$n = count($toks);
		for ($i = 0; $i < $n; $i++) {
			$t = $toks[$i];
			if ($t->is(T_NAMESPACE)) { $j = $i + 1; $ns = ''; while ($j < $n && !in_array($toks[$j]->text, [';', '{'])) { if (!$toks[$j]->is(T_WHITESPACE)) $ns .= $toks[$j]->text; $j++; } $i = $j; if ($toks[$j]->text === '{') $depth++; continue; }
			if ($t->is(T_DOC_COMMENT)) { $doc = $t->text; continue; }
			if ($t->is(T_ATTRIBUTE)) { $j = $i; $lvl = 0; $a = ''; for (; $j < $n; $j++) { $a .= $toks[$j]->text; if ($toks[$j]->text === '[' || $toks[$j]->is(T_ATTRIBUTE)) $lvl++; if ($toks[$j]->text === ']') { $lvl--; if ($lvl === 0) break; } } $pendingAttr .= $a; $i = $j; continue; }
			if (($t->is(T_CLASS) || $t->is(T_TRAIT) || $t->is(T_INTERFACE) || $t->is(T_ENUM)) && $class === null) {
				if ($i > 0 && $toks[$i-1]->is(T_DOUBLE_COLON)) continue;
				$j = $i + 1; while ($toks[$j]->is(T_WHITESPACE)) $j++;
				if (!$toks[$j]->is(T_STRING)) continue;
				$class = ltrim($ns . '\\' . $toks[$j]->text, '\\'); $classDepth = $depth; $extends = '';
				$k = $j; while ($toks[$k]->text !== '{') { if ($toks[$k]->is(T_EXTENDS)) { $m = $k + 1; while ($toks[$m]->is(T_WHITESPACE)) $m++; $extends = $toks[$m]->text; } $k++; }
				$res[$class] = ['file' => $p, 'extends' => $extends, 'attrs' => $pendingAttr, 'methods' => []];
				$pendingAttr = ''; $doc = ''; $i = $k - 1; continue;
			}
			if ($t->text === '{' || $t->is(T_CURLY_OPEN) || $t->is(T_DOLLAR_OPEN_CURLY_BRACES)) { $depth++; continue; }
			if ($t->text === '}') { $depth--; if ($class !== null && $depth === $classDepth) { $class = null; } continue; }
			if ($t->is(T_FUNCTION) && $class !== null && $depth === $classDepth + 1) {
				$j = $i + 1; while ($toks[$j]->is(T_WHITESPACE) || $toks[$j]->text === '&') $j++;
				$name = $toks[$j]->text;
				// visibilité
				$vis = 'public'; $k = $i - 1; while ($k > 0 && in_array($toks[$k]->id, [T_WHITESPACE, T_STATIC, T_FINAL, T_ABSTRACT, T_PUBLIC, T_PRIVATE, T_PROTECTED], true)) { if ($toks[$k]->is(T_PRIVATE)) $vis = 'private'; if ($toks[$k]->is(T_PROTECTED)) $vis = 'protected'; $k--; }
				// paramètres
				$k = $j; while ($toks[$k]->text !== '(') $k++; $lvl = 0; $params = []; $sig = '';
				for (; $k < $n; $k++) { $sig .= $toks[$k]->text; if ($toks[$k]->text === '(') $lvl++; if ($toks[$k]->text === ')') { $lvl--; if ($lvl === 0) break; } if ($toks[$k]->is(T_VARIABLE) && $lvl === 1) $params[] = substr($toks[$k]->text, 1); }
				// corps
				$m = $k; while ($m < $n && $toks[$m]->text !== '{' && $toks[$m]->text !== ';') $m++;
				$body = '';
				if ($toks[$m]->text === '{') { $lvl = 0; for (; $m < $n; $m++) { $tt = $toks[$m]; $body .= $tt->text; if ($tt->text === '{' || $tt->is(T_CURLY_OPEN) || $tt->is(T_DOLLAR_OPEN_CURLY_BRACES)) $lvl++; if ($tt->text === '}') { $lvl--; if ($lvl === 0) break; } } }
				$res[$class]['methods'][strtolower($name)] = ['name' => $name, 'vis' => $vis, 'params' => $params, 'attrs' => $pendingAttr, 'doc' => preg_match_all('/@(NoAdminRequired|PublicPage|NoCSRFRequired|AdminRequired|SubAdminRequired|PasswordConfirmationRequired)/', $doc, $mm) ? implode(',', array_unique($mm[1])) : '', 'hash' => md5(norm($sig . $body)), 'sig' => md5(norm($sig))];
				$pendingAttr = ''; $doc = ''; $i = $m; continue;
			}
			if (!$t->is(T_WHITESPACE) && !$t->is(T_COMMENT)) { if ($t->text === ';') { $pendingAttr = ''; $doc = ''; } }
		}
	}
}
file_put_contents($out, json_encode($res, JSON_UNESCAPED_SLASHES));
echo count($res), " classes\n";
