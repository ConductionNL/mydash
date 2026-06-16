<?php

/**
 * ActionSeedCoverageTest
 *
 * ADR-023 gate: every action key declared in `lib/actions.seed.json`
 * must have at least one `requireAction(…, 'key')` call wired in a
 * PHP controller file. This prevents seed entries from accumulating
 * without a corresponding enforcement point.
 *
 * Wave-3 C2 — migrated analytics.* and metadata-admin.* to
 * requireAction; deleted seed-only resource.* phantoms.
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

/**
 * Asserts that every key in actions.seed.json is wired to at least one
 * requireAction() call in the controller layer.
 */
class ActionSeedCoverageTest extends TestCase
{
    /**
     * Path to the seed file relative to the project root.
     */
    private const SEED_FILE = __DIR__.'/../../../lib/actions.seed.json';

    /**
     * Directory containing the PHP controllers.
     */
    private const CONTROLLER_DIR = __DIR__.'/../../../lib/Controller';

    /**
     * Every seed key must appear in at least one requireAction() call
     * inside the Controller directory.
     *
     * @return void
     */
    public function testAllSeedKeysHaveRequireActionWiring(): void
    {
        $seedPath = realpath(self::SEED_FILE);
        $this->assertNotFalse($seedPath, 'actions.seed.json not found');

        $json = file_get_contents($seedPath);
        $this->assertNotFalse($json, 'Failed to read actions.seed.json');

        $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded['actions'] ?? null, 'actions.seed.json missing "actions" key');

        $seedKeys = array_keys($decoded['actions']);
        $this->assertNotEmpty($seedKeys, 'Seed has no actions — check seed file');

        // Collect all PHP source text from the Controller directory.
        $controllerDir = realpath(self::CONTROLLER_DIR);
        $this->assertNotFalse($controllerDir, 'Controller directory not found');

        $phpFiles = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($controllerDir)
        );

        $combinedSource = '';
        foreach ($phpFiles as $file) {
            if ($file->isFile() === false || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content !== false) {
                $combinedSource .= $content;
            }
        }

        $missing = [];
        foreach ($seedKeys as $key) {
            // Match requireAction($user, 'key') — single or double quotes.
            $pattern = '/requireAction\s*\([^,]+,\s*[\'"]' . preg_quote($key, '/') . '[\'"]\s*\)/';
            if (preg_match($pattern, $combinedSource) !== 1) {
                $missing[] = $key;
            }
        }

        $this->assertEmpty(
            $missing,
            sprintf(
                "The following seed action keys have no requireAction() call in any controller:\n  - %s\n\n"
                . "Either wire them up or remove them from actions.seed.json.",
                implode("\n  - ", $missing)
            )
        );
    }//end testAllSeedKeysHaveRequireActionWiring()
}//end class
