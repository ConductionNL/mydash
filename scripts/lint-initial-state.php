<?php

/**
 * lint-initial-state.php
 *
 * CI guard for the initial-state-contract capability (REQ-INIT-001).
 *
 * Forbids any `provideInitialState(` call outside the canonical builder
 * at `lib/Service/InitialStateBuilder.php`. Runs as
 * `composer lint:initial-state` and exits non-zero on the first
 * offending file. Pure file-grep — no Composer deps required.
 *
 * @category  Tooling
 * @package   OCA\MyDash\Scripts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

$root         = dirname(__DIR__);
$libDir       = $root . '/lib';
$allowedFile  = $libDir . '/Service/InitialStateBuilder.php';
$pattern      = '/->provideInitialState\s*\(/';

/**
 * Walk a directory and return every PHP file path under it.
 *
 * @param string $dir Directory.
 *
 * @return list<string> Absolute file paths.
 */
function lint_initial_state_walk(string $dir): array
{
    $out = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if ($file->isFile() === true && $file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }
    return $out;
}

$offenders = [];
foreach (lint_initial_state_walk($libDir) as $file) {
    if ($file === $allowedFile) {
        continue;
    }
    $content = file_get_contents($file);
    if ($content === false) {
        continue;
    }
    if (preg_match($pattern, $content) === 1) {
        $offenders[] = substr($file, strlen($root) + 1);
    }
}

if ($offenders !== []) {
    fwrite(
        STDERR,
        "lint:initial-state — REQ-INIT-001 violation:\n"
        . "  Direct ->provideInitialState(...) call found outside lib/Service/InitialStateBuilder.php.\n"
        . "  Use the InitialStateBuilder service instead.\n"
        . "  Offending files:\n"
        . implode('', array_map(static fn (string $f): string => "    - {$f}\n", $offenders))
    );
    exit(1);
}

fwrite(STDOUT, "lint:initial-state — OK (no direct ->provideInitialState(...) calls outside the builder)\n");
