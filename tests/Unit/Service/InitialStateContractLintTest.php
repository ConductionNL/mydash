<?php

/**
 * InitialStateContractLintTest
 *
 * Enforces REQ-INIT-001 / REQ-INIT-003: nothing under lib/Controller or
 * lib/Settings may call IInitialState::provideInitialState directly.
 * The single allowed call site is the InitialStateBuilder::apply() method.
 *
 * Mirrors the JS-side grep lint that scans `src/` for ad-hoc
 * `loadState('mydash', ...)` calls (see TODO: the Vitest counterpart will
 * land with PR #43, `chore/add-vitest-setup`).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class InitialStateContractLintTest extends TestCase
{
    private const ALLOWED_FILE = 'InitialStateBuilder.php';

    public function testNoDirectProvideInitialStateCallsOutsideBuilder(): void
    {
        $libDir = dirname(__DIR__, 3) . '/lib';
        $this->assertDirectoryExists($libDir);

        $offenders = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $libDir,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() === false) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if ($file->getFilename() === self::ALLOWED_FILE) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            // Strip docblock contents so doc references to the method
            // (allowed) do not trigger the lint.
            $stripped = preg_replace('!/\*.*?\*/!s', '', $contents);
            if ($stripped !== null
                && preg_match('/\bprovideInitialState\s*\(/', $stripped) === 1
            ) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Direct provideInitialState() call found outside InitialStateBuilder. '
            . 'Use OCA\\MyDash\\Service\\InitialStateBuilder instead. Offenders: '
            . implode(', ', $offenders)
        );
    }
}
