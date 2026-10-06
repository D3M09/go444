<?php
/**
 * Dev-only audit: list every function CALLED in a file that is neither defined
 * there nor a PHP built-in. Used to find helpers lost when Proxy/index.php was
 * rebuilt from an older copy.
 *
 * Usage: php tools/audit_functions.php Proxy/index.php [...more files]
 */
$files = array_slice($argv, 1);
if (!$files) {
    fwrite(STDERR, "usage: php tools/audit_functions.php <file> [...]\n");
    exit(1);
}

foreach ($files as $file) {
    $src = file_get_contents($file);
    $tokens = token_get_all($src);
    $defined = [];
    $called = [];

    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_FUNCTION) {
            // Skip closures and arrow fns: `function (` / `function (` with no name.
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE], true)) {
                $j++;
            }
            if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $defined[strtolower($tokens[$j][1])] = $tokens[$j][2];
            }
            continue;
        }
        if ($t[0] === T_STRING) {
            $name = strtolower($t[1]);
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                $prev--;
            }
            // Skip method calls ($obj->foo), static calls, declarations, and
            // keywords that happen to be followed by '('.
            $prevIsObj = false;
            if ($prev >= 0) {
                if (is_array($tokens[$prev]) && $tokens[$prev][0] === T_OBJECT_OPERATOR) {
                    $prevIsObj = true;
                } elseif ($tokens[$prev] === '::') {
                    $prevIsObj = true;
                } elseif (is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    $prevIsObj = true;
                }
            }
            if ($prevIsObj) {
                continue;
            }
            // Must be immediately followed by '(' to count as a call.
            $k = $i + 1;
            while ($k < $count && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                $k++;
            }
            if ($k < $count && $tokens[$k] === '(') {
                $called[$name] = $t[2];
            }
        }
    }

    $builtins = array_flip(get_defined_functions()['internal']);
    $missing = [];
    foreach ($called as $name => $line) {
        if (isset($defined[$name]) || isset($builtins[$name])) {
            continue;
        }
        $missing[$name] = $line;
    }
    ksort($missing);

    echo "== $file : " . count($called) . " calls, " . count($missing) . " unresolved\n";
    foreach ($missing as $name => $line) {
        printf("   %-32s line %d\n", $name . '()', $line);
    }
    echo "\n";
}