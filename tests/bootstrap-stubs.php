<?php

/**
 * Stub-only bootstrap for unit tests running outside a full Nextcloud installation.
 *
 * Uses vendor/nextcloud/ocp stubs so PHPUnit createMock() can introspect OCP
 * interfaces without needing a live Nextcloud database.
 *
 * @category Test
 * @package  OCA\MyDash\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

require_once __DIR__ . '/../vendor/autoload.php';

// Register OCP stubs from vendor.
$ocpLoader = new \Composer\Autoload\ClassLoader();
$ocpLoader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
$ocpLoader->register();

// Doctrine DBAL placeholders so IDBConnection can be mocked.
require_once __DIR__ . '/Stubs/DoctrineStubs.php';

error_log('[UNIT TEST BOOTSTRAP] Stub-only bootstrap loaded — OCP stubs active');
