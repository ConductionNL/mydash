<?php

/**
 * FeedTokenTableBuilder
 *
 * Builder for the feed-tokens database table schema. Materialises the
 * `oc_mydash_feed_tokens` table that backs the per-user RSS / Atom
 * feed-token capability (REQ-FEED-001..009). One row per user
 * (`UNIQUE(user_id)`) plus a fast `token` lookup index for the public
 * `/feed/{token}.xml` endpoint.
 *
 * @category  Migration
 * @package   OCA\MyDash\Migration
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

namespace OCA\MyDash\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;

/**
 * Builder for the dashboard feed-tokens table schema.
 */
class FeedTokenTableBuilder
{
    /**
     * Create the mydash_feed_tokens table when missing.
     *
     * Column layout:
     *  - id           BIGINT PK auto-increment
     *  - user_id      VARCHAR(64) NOT NULL  (UNIQUE — one token per user)
     *  - token        VARCHAR(64) NOT NULL  (indexed for public lookup)
     *  - created_at   DATETIME    NOT NULL
     *  - last_used_at DATETIME    NULLABLE  (touched on every public hit)
     *  - revoked_at   DATETIME    NULLABLE  (soft-revoke flag)
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     *
     * @return void
     */
    public static function create(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('mydash_feed_tokens') === true) {
            return;
        }

        $table = $schema->createTable('mydash_feed_tokens');

        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]
        );
        $table->addColumn(
            'user_id',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'token',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'created_at',
            Types::DATETIME,
            ['notnull' => true]
        );
        $table->addColumn(
            'last_used_at',
            Types::DATETIME,
            ['notnull' => false]
        );
        $table->addColumn(
            'revoked_at',
            Types::DATETIME,
            ['notnull' => false]
        );

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(
            ['user_id'],
            'mydash_feed_tok_user_uq'
        );
        $table->addIndex(
            ['token'],
            'mydash_feed_tok_tok_idx'
        );
    }//end create()
}//end class
