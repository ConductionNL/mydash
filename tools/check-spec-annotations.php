<?php

/**
 * check-spec-annotations.php
 *
 * Fails when a public method in `lib/` lacks both an `@spec` PHPDoc tag
 * and an entry on `tools/spec-annotations-allowlist.txt`.
 *
 * Enforces the contract added by the
 * 2026-05-03-spec-annotation-pass change. Run via
 * `composer lint:spec-annotations`.
 *
 * @category  Tools
 * @package   OCA\MyDash
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

$repoRoot      = dirname(__DIR__);
$libDir        = $repoRoot.'/lib';
$allowlistFile = __DIR__.'/spec-annotations-allowlist.txt';

if (is_dir($libDir) === false) {
    fwrite(STDERR, "ERROR: lib/ not found at {$libDir}\n");
    exit(2);
}

if (is_file($allowlistFile) === false) {
    fwrite(STDERR, "ERROR: allowlist not found at {$allowlistFile}\n");
    exit(2);
}

$allowed = [];
$lines   = file($allowlistFile, (FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '#') === true) {
        continue;
    }

    $allowed[$trimmed] = true;
}

/**
 * Pull the namespace + class/interface/trait name from a PHP source.
 *
 * @param string $src The PHP source.
 *
 * @return string|null The fully-qualified type name, or null if none.
 */
function fqn(string $src): ?string
{
    $ns = null;
    if (preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $src, $m) === 1) {
        $ns = $m[1];
    }

    if (preg_match(
        '/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+(\w+)/m',
        $src,
        $m
    ) !== 1) {
        return null;
    }

    return ($ns === null) ? $m[1] : $ns.'\\'.$m[1];
}//end fqn()

/**
 * Walk the file and return [methodName => hasSpecTag] for public methods.
 *
 * @param string $src The PHP source.
 *
 * @return array<string, bool>
 */
function publicMethods(string $src): array
{
    $lines = explode("\n", $src);
    $out   = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*public\s+function\s+(\w+)\s*\(/', $line, $m) !== 1) {
            continue;
        }

        $name = $m[1];
        if ($name === '__construct') {
            continue;
        }

        // Walk back past attributes and blank lines.
        $j = ($i - 1);
        while ($j >= 0) {
            $stripped = trim($lines[$j]);
            if ($stripped === '' || str_starts_with($stripped, '#[') === true) {
                $j--;
                continue;
            }

            break;
        }

        $hasSpec = false;
        if ($j >= 0 && str_contains($lines[$j], '*/') === true) {
            $k     = $j;
            $block = '';
            while ($k >= 0) {
                $block = $lines[$k]."\n".$block;
                if (str_contains($lines[$k], '/**') === true) {
                    break;
                }

                $k--;
            }

            if (str_contains($block, '@spec ') === true) {
                $hasSpec = true;
            }
        }

        $out[$name] = $hasSpec;
    }

    return $out;
}//end publicMethods()

$rii      = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($libDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
$failures = [];
foreach ($rii as $file) {
    if ($file->isFile() === false || $file->getExtension() !== 'php') {
        continue;
    }

    $src   = file_get_contents($file->getPathname());
    $class = fqn($src);
    if ($class === null) {
        continue;
    }

    foreach (publicMethods($src) as $method => $hasSpec) {
        if ($hasSpec === true) {
            continue;
        }

        $key = $class.'::'.$method;
        if (isset($allowed[$key]) === true) {
            continue;
        }

        $failures[] = $key.'  ('.str_replace($repoRoot.'/', '', $file->getPathname()).')';
    }
}

if (count($failures) === 0) {
    echo "spec-annotations: OK — every public lib/ method has @spec or is on the allow-list\n";
    exit(0);
}

fwrite(STDERR, "spec-annotations: FAIL — the following public methods need an @spec tag\n");
fwrite(STDERR, "                 (or an entry on tools/spec-annotations-allowlist.txt):\n\n");
foreach ($failures as $f) {
    fwrite(STDERR, "  - {$f}\n");
}

fwrite(STDERR, "\nSee openspec/changes/archive/2026-05-03-spec-annotation-pass/ for the contract.\n");
exit(1);
