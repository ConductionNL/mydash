<?php

/**
 * AdminTemplateServiceGrepGuardTest
 *
 * Single-source-of-truth grep guard (REQ-TMPL-013): the only place in
 * `lib/` that may invoke `IGroupManager::getUserGroupIds` is
 * `AdminTemplateService::getUserGroupIdsFor`. Every other workspace,
 * dashboard, permission, template, and rule code path MUST consume the
 * resolver instead — otherwise the routing algorithm could drift between
 * call sites and the design's "one source of truth" invariant breaks.
 *
 * The guard is intentionally a static grep over the source tree (not a
 * runtime mock assertion) so a developer adding a fresh
 * `$this->groupManager->getUserGroupIds(...)` call in any future feature
 * gets a hard test failure long before the change reaches review.
 *
 * If this test fails, refactor the new call site to use
 * `AdminTemplateService::getUserGroupIdsFor` (or, if the call computes
 * a primary group, `AdminTemplateService::resolvePrimaryGroup`).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Grep guard test for the routing resolver invariant (REQ-TMPL-013).
 */
class AdminTemplateServiceGrepGuardTest extends TestCase
{
    /**
     * Pattern that signals a direct `IGroupManager::getUserGroupIds(...)`
     * call. We deliberately match the method-call form (`->getUserGroupIds(`)
     * so doc references like `IGroupManager::getUserGroupIds` and the
     * resolver's own `getUserGroupIdsFor` wrapper do NOT trigger the guard.
     *
     * @var string
     */
    private const FORBIDDEN_CALL = '->getUserGroupIds(';

    /**
     * The single file allowed to make the call.
     *
     * @var string
     */
    private const RESOLVER_FILE = 'AdminTemplateService.php';

    /**
     * REQ-TMPL-013: every direct `->getUserGroupIds(` call in `lib/` MUST
     * live inside `AdminTemplateService.php`. Any other call site is a
     * parallel implementation of the routing algorithm and breaks the
     * single-source-of-truth invariant.
     *
     * @return void
     */
    public function testIGroupManagerCallIsConfinedToResolver(): void
    {
        $libRoot = realpath(path: __DIR__ . '/../../../lib');
        $this->assertIsString(
            actual: $libRoot,
            message: 'lib/ directory must exist relative to test file'
        );

        $offenders = [];
        $iterator  = new RecursiveIteratorIterator(
            iterator: new RecursiveDirectoryIterator(directory: $libRoot)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() === false) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $basename = $file->getBasename();
            if ($basename === self::RESOLVER_FILE) {
                continue;
            }

            $contents = file_get_contents(filename: $file->getPathname());
            if ($contents === false) {
                continue;
            }

            if (str_contains(haystack: $contents, needle: self::FORBIDDEN_CALL) === true) {
                $offenders[] = substr(
                    string: $file->getPathname(),
                    offset: (strlen(string: $libRoot) + 1)
                );
            }
        }

        $this->assertSame(
            expected: [],
            actual: $offenders,
            message: implode(
                separator: "\n",
                array: [
                    'REQ-TMPL-013 violation: the routing resolver invariant requires that',
                    '`->getUserGroupIds(` is called ONLY from AdminTemplateService.php.',
                    'These files violate the rule:',
                    '  - ' . implode(separator: "\n  - ", array: $offenders),
                    'Fix: refactor to consume AdminTemplateService::getUserGroupIdsFor()',
                    'or AdminTemplateService::resolvePrimaryGroup() instead.',
                ]
            )
        );
    }//end testIGroupManagerCallIsConfinedToResolver()

    /**
     * Sanity check: confirm the resolver itself still contains the call —
     * otherwise the guard test would silently pass even if the routing
     * resolver were accidentally deleted.
     *
     * @return void
     */
    public function testResolverItselfStillCallsGroupManager(): void
    {
        $resolverPath = realpath(
            path: __DIR__ . '/../../../lib/Service/' . self::RESOLVER_FILE
        );
        $this->assertIsString(
            actual: $resolverPath,
            message: 'AdminTemplateService.php must exist'
        );

        $contents = file_get_contents(filename: $resolverPath);
        $this->assertIsString(actual: $contents);

        $this->assertStringContainsString(
            needle: self::FORBIDDEN_CALL,
            haystack: $contents,
            message: 'AdminTemplateService.php MUST still call '
                . '$this->groupManager->getUserGroupIds(...) — the grep guard '
                . 'is meaningless if the resolver no longer makes the call.'
        );
    }//end testResolverItselfStillCallsGroupManager()
}//end class
