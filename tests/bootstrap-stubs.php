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

// Doctrine DBAL placeholders MUST be loaded before the OCP PSR-4 loader is
// registered. IQueryBuilder.php (used by IDBConnection) contains class
// constants that reference Doctrine\DBAL\ParameterType directly
// (e.g. `PARAM_NULL = ParameterType::NULL`). PHP evaluates these constant
// expressions as soon as the interface file is parsed — before any mock
// creation code runs. If Doctrine is not yet defined at that moment, PHP
// emits a fatal "Class not found" error. Loading the stubs first ensures
// that the placeholder classes are already in the class table when the OCP
// autoloader first touches IQueryBuilder.php.
require_once __DIR__ . '/Stubs/DoctrineStubs.php';

// Register OCP stubs from vendor.
$ocpLoader = new \Composer\Autoload\ClassLoader();
$ocpLoader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
$ocpLoader->register();

error_log('[UNIT TEST BOOTSTRAP] Stub-only bootstrap loaded — OCP stubs active');
