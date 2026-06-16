<?php

/**
 * FeedTokenServiceTest
 *
 * Unit tests for the {@see FeedTokenService} (REQ-FEED-001..009): token
 * issue, idempotent regenerate (revoke + insert in one transaction),
 * idempotent soft-revoke, and resolve-with-touch.
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

use OCA\MyDash\Db\FeedToken;
use OCA\MyDash\Db\FeedTokenMapper;
use OCA\MyDash\Service\FeedTokenService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for FeedTokenService (REQ-FEED-001..009).
 */
class FeedTokenServiceTest extends TestCase
{

    /** @var FeedTokenMapper&MockObject */
    private $mapper;

    /** @var IDBConnection&MockObject */
    private $db;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private FeedTokenService $service;

    /**
     * Wire up mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = $this->createMock(FeedTokenMapper::class);
        $this->db     = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new FeedTokenService(
            mapper: $this->mapper,
            db: $this->db,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * `getOrCreateToken` returns the existing row when one is on file.
     * (REQ-FEED-001 — "Request token when one already exists".)
     *
     * @return void
     */
    public function testGetOrCreateReturnsExistingToken(): void
    {
        $existing = new FeedToken();
        $existing->setUserId('alice');
        $existing->setToken('existing-token');

        $this->mapper->expects($this->once())
            ->method('findByUserId')
            ->with('alice')
            ->willReturn($existing);
        $this->mapper->expects($this->never())->method('insert');

        $result = $this->service->getOrCreateToken(userId: 'alice');
        $this->assertSame(expected: 'existing-token', actual: $result->getToken());
    }//end testGetOrCreateReturnsExistingToken()

    /**
     * `getOrCreateToken` issues a fresh token on first call.
     * (REQ-FEED-001 — "Request token for first time".)
     *
     * @return void
     */
    public function testGetOrCreateIssuesTokenOnFirstCall(): void
    {
        $this->mapper->expects($this->once())
            ->method('findByUserId')
            ->with('bob')
            ->willReturn(null);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static function (FeedToken $entity): FeedToken {
                $entity->setId(value: 1);
                return $entity;
            });

        $result = $this->service->getOrCreateToken(userId: 'bob');
        $this->assertSame(expected: 'bob', actual: $result->getUserId());
        $this->assertNotEmpty(actual: $result->getToken());
        $this->assertNotEmpty(actual: $result->getCreatedAt());
    }//end testGetOrCreateIssuesTokenOnFirstCall()

    /**
     * `regenerateToken` revokes the active token AND inserts a new
     * one inside one DB transaction. (REQ-FEED-002.)
     *
     * @return void
     */
    public function testRegenerateRevokesAndIssuesInTransaction(): void
    {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        // The unique constraint on `user_id` cannot coexist with the
        // soft-revoke pattern, so regenerate hard-deletes every prior
        // row (active + revoked) before inserting the new one.
        $this->mapper->expects($this->once())
            ->method('deleteAllForUser')
            ->with('carol');
        $this->mapper->expects($this->never())->method('softRevoke');

        $captured = null;
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static function (FeedToken $entity) use (&$captured): FeedToken {
                $entity->setId(value: 99);
                $captured = $entity;
                return $entity;
            });

        $fresh = $this->service->regenerateToken(userId: 'carol');
        $this->assertNotNull(actual: $captured);
        $this->assertSame(expected: 'carol', actual: $fresh->getUserId());
        $this->assertNotEmpty(actual: $fresh->getToken());
    }//end testRegenerateRevokesAndIssuesInTransaction()

    /**
     * `regenerateToken` works for a user with no prior token (no
     * pre-existing row to revoke). (REQ-FEED-002 — "Regenerate when
     * no token exists".)
     *
     * @return void
     */
    public function testRegenerateWithoutPriorToken(): void
    {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        // `deleteAllForUser` is unconditional — when the user has no
        // prior row it is simply a 0-row delete.
        $this->mapper->expects($this->once())
            ->method('deleteAllForUser')
            ->with('dan');
        $this->mapper->expects($this->never())->method('softRevoke');
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static function (FeedToken $entity): FeedToken {
                $entity->setId(value: 7);
                return $entity;
            });

        $fresh = $this->service->regenerateToken(userId: 'dan');
        $this->assertSame(expected: 'dan', actual: $fresh->getUserId());
    }//end testRegenerateWithoutPriorToken()

    /**
     * `revokeToken` is idempotent — succeeds with no DB writes when
     * the user has never opted in. (REQ-FEED-003 — "Revoke when no
     * token exists".)
     *
     * @return void
     */
    public function testRevokeIsIdempotentWhenNoToken(): void
    {
        $this->mapper->expects($this->once())
            ->method('findByUserId')
            ->with('eve')
            ->willReturn(null);
        $this->mapper->expects($this->never())->method('softRevoke');

        $this->service->revokeToken(userId: 'eve');
        $this->assertTrue(condition: true);
    }//end testRevokeIsIdempotentWhenNoToken()

    /**
     * `revokeToken` soft-revokes the active row when one exists.
     * (REQ-FEED-003.)
     *
     * @return void
     */
    public function testRevokeSoftRevokesActiveRow(): void
    {
        $existing = new FeedToken();
        $existing->setUserId('frank');
        $existing->setToken('to-revoke');

        $this->mapper->expects($this->once())
            ->method('findByUserId')
            ->with('frank')
            ->willReturn($existing);
        $this->mapper->expects($this->once())
            ->method('softRevoke')
            ->with($existing);

        $this->service->revokeToken(userId: 'frank');
        $this->assertTrue(condition: true);
    }//end testRevokeSoftRevokesActiveRow()

    /**
     * `resolveToken` returns null for an unknown token (REQ-FEED-004).
     *
     * @return void
     */
    public function testResolveTokenReturnsNullForUnknownToken(): void
    {
        $this->mapper->expects($this->once())
            ->method('findByToken')
            ->with('not-a-real-token')
            ->willThrowException(new DoesNotExistException(msg: 'no row'));

        $this->assertNull(
            actual: $this->service->resolveToken(token: 'not-a-real-token')
        );
    }//end testResolveTokenReturnsNullForUnknownToken()

    /**
     * `resolveToken` returns null for an empty token without hitting
     * the DB (defence in depth — pulled out of the route requirement
     * `[A-Za-z0-9_\-]+` already enforces non-empty, this is a guard).
     *
     * @return void
     */
    public function testResolveTokenReturnsNullForEmptyString(): void
    {
        $this->mapper->expects($this->never())->method('findByToken');
        $this->assertNull(actual: $this->service->resolveToken(token: ''));
    }//end testResolveTokenReturnsNullForEmptyString()

    /**
     * `resolveToken` touches `lastUsedAt` on the way out for a valid
     * token. (REQ-FEED-004 — `lastUsedAt` MUST be updated on success.)
     *
     * @return void
     */
    public function testResolveTokenTouchesLastUsedOnHit(): void
    {
        $row = new FeedToken();
        $row->setUserId('grace');
        $row->setToken('valid-token');

        $this->mapper->expects($this->once())
            ->method('findByToken')
            ->with('valid-token')
            ->willReturn($row);
        $this->mapper->expects($this->once())
            ->method('updateLastUsed')
            ->with($row);

        $resolved = $this->service->resolveToken(token: 'valid-token');
        $this->assertNotNull(actual: $resolved);
        $this->assertSame(expected: 'grace', actual: $resolved->getUserId());
    }//end testResolveTokenTouchesLastUsedOnHit()

    /**
     * `generateTokenString` produces a 43-character URL-safe base64
     * string with no `+`, `/`, or `=` (REQ-FEED-009).
     *
     * @return void
     */
    public function testGenerateTokenStringIsUrlSafe(): void
    {
        $token = FeedTokenService::generateTokenString();
        $this->assertSame(expected: 43, actual: strlen(string: $token));
        $this->assertDoesNotMatchRegularExpression(
            pattern: '~[+/=]~',
            string: $token
        );
        $this->assertMatchesRegularExpression(
            pattern: '~^[A-Za-z0-9_\-]+$~',
            string: $token
        );
    }//end testGenerateTokenStringIsUrlSafe()

    /**
     * Two consecutive token generations are not equal — non-enumerable
     * (REQ-FEED-009).
     *
     * @return void
     */
    public function testGenerateTokenStringIsUnique(): void
    {
        $a = FeedTokenService::generateTokenString();
        $b = FeedTokenService::generateTokenString();
        $this->assertNotSame(expected: $a, actual: $b);
    }//end testGenerateTokenStringIsUnique()
}//end class
