<?php

/**
 * Doctrine DBAL stubs for unit tests.
 *
 * The Nextcloud OCP signature stubs at
 * `vendor/nextcloud/ocp/OCP/IDBConnection.php` and
 * `vendor/nextcloud/ocp/OCP/DB/QueryBuilder/IQueryBuilder.php`
 * reference Doctrine DBAL classes in their type-hints. Doctrine DBAL
 * is provided at runtime by Nextcloud (not as a composer dependency
 * of this app), so when PHPUnit's automatic mock generator
 * introspects `IDBConnection` to build a test double, the autoloader
 * fails on the missing Doctrine classes.
 *
 * Loading this file before `createMock(IDBConnection::class)` defines
 * minimal placeholder classes so the type-hints resolve. Inside the
 * Nextcloud Docker container the real Doctrine classes are already
 * loaded, so the `class_exists` guards turn this into a no-op.
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Doctrine\DBAL {
    if (class_exists(__NAMESPACE__ . '\\ParameterType', false) === false) {
        class ParameterType
        {
            public const NULL         = 0;
            public const INTEGER      = 1;
            public const STRING       = 2;
            public const LARGE_OBJECT = 3;
            public const BOOLEAN      = 5;
            public const BINARY       = 16;
            public const ASCII        = 17;
        }
        class ArrayParameterType
        {
            public const INTEGER = 101;
            public const STRING  = 102;
            public const ASCII   = 103;
            public const BINARY  = 116;
        }
        class Connection
        {
        }
    }
}

namespace Doctrine\DBAL\Types {
    if (class_exists(__NAMESPACE__ . '\\Types', false) === false) {
        class Types
        {
            public const BOOLEAN              = 'boolean';
            public const DATETIME_MUTABLE     = 'datetime';
            public const DATETIME_IMMUTABLE   = 'datetime_immutable';
            public const DATETIMETZ_MUTABLE   = 'datetimetz';
            public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
            public const DATE_MUTABLE         = 'date';
            public const DATE_IMMUTABLE       = 'date_immutable';
            public const TIME_MUTABLE         = 'time';
            public const TIME_IMMUTABLE       = 'time_immutable';
        }
    }
}

namespace Doctrine\DBAL\Schema {
    if (class_exists(__NAMESPACE__ . '\\Schema', false) === false) {
        class Schema
        {
        }
    }
}

namespace OC\DB\QueryBuilder\Sharded {
    if (class_exists(__NAMESPACE__ . '\\CrossShardMoveHelper', false) === false) {
        class CrossShardMoveHelper
        {
        }
        class ShardDefinition
        {
        }
    }
}
