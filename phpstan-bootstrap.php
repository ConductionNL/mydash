<?php

/**
 * PHPStan bootstrap file - registers OCP autoloader for static analysis.
 *
 * The nextcloud/ocp package ships PHP class stubs at vendor/nextcloud/ocp/OCP/
 * but its composer.json does not register a PSR-4 autoload mapping. We add the
 * mappings here so PHPStan can resolve OCP\* and NCU\* class references.
 */

$autoloader = require __DIR__ . '/vendor/autoload.php';
$autoloader->addPsr4('OCP\\', __DIR__ . '/vendor/nextcloud/ocp/OCP/');
$autoloader->addPsr4('NCU\\', __DIR__ . '/vendor/nextcloud/ocp/NCU/');
