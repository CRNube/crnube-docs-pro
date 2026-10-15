<?php
/**
 * Builds the Spanish translations from one source, languages/{domain}-es_CR.l10n.php:
 *
 * - copies it to the other Spanish locales (es_ES, es_MX, ...);
 * - writes the JSON files WordPress loads for each built script (wp_set_script_translations),
 *   with only the strings that script uses, and deletes JSON files of scripts that no
 *   longer exist;
 * - reports strings used in the PHP or JS code that are missing from the source.
 *
 * Usage: php bin/translations.php [--prune]   (exit code 1 if a string is missing)
 * --prune removes from the source the entries no code uses any more.
 *
 * A copy of CRNube Docs' bin/translations.php; only $domain and $scripts change.
 */

$root    = dirname(__DIR__);
$domain  = 'crnube-docs-pro';
$locales = ['es_CR', 'es_ES', 'es_MX', 'es_CO', 'es_AR', 'es_CL', 'es_PE', 'es_VE', 'es_GT', 'es_UY', 'es_EC', 'es_PR', 'es_DO'];

/** Built script (relative path, as WordPress hashes it) => folder with its sources. */
$scripts = [
    'build/library-pro.js' => 'assets/src/library',
    'build/reports.js'     => 'assets/src/reports',
    'build/access.js'      => 'assets/src/access',
];

$source   = $root . "/languages/{$domain}-es_CR.l10n.php";
$data     = require $source;
$messages = $data['messages'];

/** @return array<string, true> */
function crnd_php_strings(string $root, string $domain): array
{
    $funcs = ['__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e', '_n', '_x'];
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (!str_ends_with($path, '.php') || preg_match('#/(vendor|node_modules|languages|bin|tests|build)/#', $path)) {
            continue;
        }
        $tokens = token_get_all((string) file_get_contents($path));
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], $funcs, true)) {
                continue;
            }
            $args = [];
            for ($j = $i + 1, $n = count($tokens); $j < $n && count($args) < 4; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $args[] = eval('return ' . $t[1] . ';');
                } elseif ($t === ')' || $t === ';') {
                    break;
                }
            }
            // Only calls of this text domain (the last string argument).
            if ($args === [] || end($args) !== $domain || count($args) < 2) {
                continue;
            }
            $found[$token[1] === '_n' && count($args) >= 3 ? $args[0] . "\0" . $args[1] : $args[0]] = true;
        }
    }
    return $found;
}

/** @return array<string, true> Strings of __( '…' ) and _n( '…', '…' ) calls in a JS folder. */
function crnd_js_strings(string $dir, string $domain): array
{
    $string = "'((?:\\\\.|[^'\\\\])*)'";
    $found  = [];
    $files  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!preg_match('/\.(js|jsx)$/', $file->getFilename())) {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code); // translator comments sit inside calls
        $unescape = static fn (string $s): string => stripcslashes($s);
        $dom      = preg_quote("'" . $domain . "'", '/');

        preg_match_all('/\b_n\(\s*' . $string . '\s*,\s*' . $string . '\s*,[^,]+,\s*' . $dom . '/s', $code, $plurals, PREG_SET_ORDER);
        foreach ($plurals as $m) {
            $found[$unescape($m[1]) . "\0" . $unescape($m[2])] = true;
        }
        preg_match_all('/\b__\(\s*' . $string . '\s*,\s*' . $dom . '/s', $code, $singles, PREG_SET_ORDER);
        foreach ($singles as $m) {
            $found[$unescape($m[1])] = true;
        }
    }
    return $found;
}

$found    = crnd_php_strings($root, $domain);
$byScript = [];
foreach ($scripts as $script => $dir) {
    $byScript[$script] = crnd_js_strings($root . '/' . $dir, $domain);
    $found += $byScript[$script];
}

$missing = array_diff(array_keys($found), array_keys($messages));
foreach ($missing as $string) {
    fwrite(STDERR, 'Missing translation: ' . str_replace("\0", ' | ', $string) . PHP_EOL);
}

$unused = array_diff(array_keys($messages), array_keys($found));
if ($unused !== [] && in_array('--prune', $argv, true)) {
    // One entry per line in the source, so unused ones are removed line by line.
    $lines = file($source);
    $kept  = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s+(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")\s*=>/', $line, $m)) {
            $key = eval('return ' . $m[1] . ';');
            if (in_array($key, $unused, true)) {
                continue;
            }
        }
        $kept[] = $line;
    }
    file_put_contents($source, implode('', $kept));
    $data     = require $source;
    $messages = $data['messages'];
    echo count($unused) . " unused entries removed from the source.\n";
    $unused = [];
}

$current = [];
foreach ($locales as $locale) {
    if ($locale !== 'es_CR') {
        $copy             = $data;
        $copy['language'] = $locale;
        file_put_contents(
            $root . "/languages/{$domain}-{$locale}.l10n.php",
            "<?php\n// Generated from {$domain}-es_CR.l10n.php by bin/translations.php. Do not edit.\nreturn " . var_export($copy, true) . ";\n"
        );
    }

    foreach ($byScript as $script => $strings) {
        $jed = ['' => ['domain' => 'messages', 'lang' => $locale, 'plural-forms' => $data['plural-forms']]];
        foreach (array_keys($strings) as $key) {
            if (!isset($messages[$key])) {
                continue;
            }
            $singular       = explode("\0", $key)[0];
            $jed[$singular] = explode("\0", $messages[$key]);
        }
        $file           = "{$domain}-{$locale}-" . md5($script) . '.json';
        $current[$file] = true;
        file_put_contents(
            $root . '/languages/' . $file,
            json_encode([
                'translation-revision-date' => gmdate('Y-m-d H:i:sO'),
                'generator'                 => $domain . '/bin/translations.php',
                'source'                    => $script,
                'domain'                    => 'messages',
                'locale_data'               => ['messages' => $jed],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}

// JSON files of scripts that were removed or renamed.
foreach (glob($root . "/languages/{$domain}-es_*-*.json") ?: [] as $json) {
    if (!isset($current[basename($json)])) {
        unlink($json);
    }
}

echo count($found) . ' strings (' . array_sum(array_map('count', $byScript)) . ' in JS), ' . count($missing) . ' missing, '
    . ($unused !== [] ? count($unused) . ' unused (run with --prune), ' : '') . count($locales) . " locales written.\n";
exit($missing === [] ? 0 : 1);
