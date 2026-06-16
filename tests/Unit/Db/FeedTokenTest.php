<?php

/**
 * FeedToken Entity Test
 *
 * Unit tests for the FeedToken entity (REQ-FEED-001..009).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Db;

use OCA\MyDash\Db\FeedToken;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the FeedToken entity.
 */
class FeedTokenTest extends TestCase
{
    /**
     * The entity under test.
     *
     * @var FeedToken
     */
    private FeedToken $token;

    /**
     * Reset the entity before each scenario.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->token = new FeedToken();
    }//end setUp()

    /**
     * Verifies the constructor registers the integer id type so that
     * the auto-increment column hydrates as int rather than string.
     *
     * @return void
     */
    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->token->getFieldTypes();
        $this->assertSame(expected: 'integer', actual: $fieldTypes['id']);
    }//end testConstructorRegistersFieldTypes()

    /**
     * Default values for the entity match the "fresh row, never
     * persisted" state used by the service to detect "no token yet".
     *
     * @return void
     */
    public function testDefaultsAreNull(): void
    {
        $this->assertNull(actual: $this->token->getUserId());
        $this->assertNull(actual: $this->token->getToken());
        $this->assertNull(actual: $this->token->getCreatedAt());
        $this->assertNull(actual: $this->token->getLastUsedAt());
        $this->assertNull(actual: $this->token->getRevokedAt());
    }//end testDefaultsAreNull()

    /**
     * `isValid` returns true exactly when `revokedAt` is null.
     *
     * @return void
     */
    public function testIsValidWhenRevokedAtIsNull(): void
    {
        $this->assertTrue(condition: $this->token->isValid());
        $this->assertFalse(condition: $this->token->isRevoked());
    }//end testIsValidWhenRevokedAtIsNull()

    /**
     * Stamping `revokedAt` flips both helper predicates.
     *
     * @return void
     */
    public function testIsRevokedWhenRevokedAtIsSet(): void
    {
        $this->token->setRevokedAt('2026-05-02 12:34:56');
        $this->assertFalse(condition: $this->token->isValid());
        $this->assertTrue(condition: $this->token->isRevoked());
    }//end testIsRevokedWhenRevokedAtIsSet()

    /**
     * `jsonSerialize` returns every column as an associative-array key
     * so the management endpoints can echo the row back to the caller.
     *
     * @return void
     */
    public function testJsonSerializeIncludesAllColumns(): void
    {
        $this->token->setUserId('alice');
        $this->token->setToken('opaque-token-string');
        $this->token->setCreatedAt('2026-05-02 10:00:00');
        $this->token->setLastUsedAt('2026-05-02 11:00:00');
        $this->token->setRevokedAt(null);

        $serialised = $this->token->jsonSerialize();

        $this->assertSame(expected: 'alice', actual: $serialised['userId']);
        $this->assertSame(expected: 'opaque-token-string', actual: $serialised['token']);
        $this->assertSame(expected: '2026-05-02 10:00:00', actual: $serialised['createdAt']);
        $this->assertSame(expected: '2026-05-02 11:00:00', actual: $serialised['lastUsedAt']);
        $this->assertNull(actual: $serialised['revokedAt']);
    }//end testJsonSerializeIncludesAllColumns()
}//end class
