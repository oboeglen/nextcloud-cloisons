<?php
// usage: php meth.php <file> <method>  → source de la méthode
[$_, $file, $name] = $argv; $toks = PhpToken::tokenize(file_get_contents($file)); $n = count($toks);
for ($i = 0; $i < $n; $i++) {
	if (!$toks[$i]->is(T_FUNCTION)) continue;
	$j = $i + 1; while ($toks[$j]->is(T_WHITESPACE) || $toks[$j]->text === '&') $j++;
	if (strtolower($toks[$j]->text) !== strtolower($name)) continue;
	$s = $i; while ($s > 0 && !in_array($toks[$s-1]->text, [';', '{', '}'])) $s--;
	$out = ''; $lvl = 0; $started = false;
	for ($k = $s; $k < $n; $k++) { $out .= $toks[$k]->text; if ($toks[$k]->text === '{' || $toks[$k]->is(T_CURLY_OPEN) || $toks[$k]->is(T_DOLLAR_OPEN_CURLY_BRACES)) { $lvl++; $started = true; } if ($toks[$k]->text === '}') { $lvl--; if ($started && $lvl === 0) break; } if (!$started && $toks[$k]->text === ';') break; }
	echo trim($out), "\n"; exit;
}
