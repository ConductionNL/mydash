<?php

/**
 * TileApiControllerTest
 *
 * Covers REQ-TILE-001 (DEPRECATED) — POST/PUT/DELETE return HTTP 410
 * Gone with the documented `{status, message, replacement}` envelope and
 * GET endpoints continue to serve existing rows for backwards compat.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\MyDash\Controller\TileApiController;
use OCA\MyDash\Db\Tile;
use OCA\MyDash\Service\TileService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TileApiControllerTest extends TestCase
{
    /** @var TileService&MockObject */
    private TileService $tileService;
    /** @var IRequest&MockObject */
    private IRequest $request;
    /** @var IL10N&MockObject */
    private IL10N $l10n;

    protected function setUp(): void
    {
        $this->tileService = $this->createMock(TileService::class);
        $this->request     = $this->createMock(IRequest::class);
        $this->l10n        = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);
    }

    private function makeController(?string $userId = 'alice'): TileApiController
    {
        return new TileApiController(
            request: $this->request,
            tileService: $this->tileService,
            l10n: $this->l10n,
            userId: $userId,
        );
    }

    public function testCreateReturns410GoneEnvelope(): void
    {
        $this->tileService->expects($this->never())->method('createTile');

        $controller = $this->makeController();
        $response   = $controller->create();
        $data       = $response->getData();

        $this->assertSame(Http::STATUS_GONE, $response->getStatus());
        $this->assertIsArray($data);
        $this->assertSame('gone', $data['status']);
        $this->assertNotEmpty($data['message']);
        $this->assertSame(
            'POST /api/dashboards/{uuid}/widgets with type:tile',
            $data['replacement']
        );
    }

    public function testUpdateReturns410GoneEnvelope(): void
    {
        $this->tileService->expects($this->never())->method('updateTile');

        $controller = $this->makeController();
        $response   = $controller->update();
        $data       = $response->getData();

        $this->assertSame(Http::STATUS_GONE, $response->getStatus());
        $this->assertSame('gone', $data['status']);
        $this->assertSame(TileApiController::REPLACEMENT_HINT, $data['replacement']);
    }

    public function testDestroyReturns410GoneEnvelope(): void
    {
        $this->tileService->expects($this->never())->method('deleteTile');

        $controller = $this->makeController();
        $response   = $controller->destroy();
        $data       = $response->getData();

        $this->assertSame(Http::STATUS_GONE, $response->getStatus());
        $this->assertSame('gone', $data['status']);
        $this->assertNotEmpty($data['message']);
        $this->assertSame(TileApiController::REPLACEMENT_HINT, $data['replacement']);
    }

    public function testIndexStillReturnsExistingRowsForBackwardsCompat(): void
    {
        $tile = new Tile();
        $tile->setId(7);
        $tile->setUserId('alice');
        $tile->setTitle('Files');
        $tile->setIcon('icon-folder');
        $tile->setIconType('class');
        $tile->setBackgroundColor('#3b82f6');
        $tile->setTextColor('#ffffff');
        $tile->setLinkType('app');
        $tile->setLinkValue('/apps/files');

        $this->tileService
            ->expects($this->once())
            ->method('getUserTiles')
            ->with('alice')
            ->willReturn([$tile]);

        $controller = $this->makeController('alice');
        $response   = $controller->index();
        $data       = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Files', $data[0]['title']);
    }

    public function testIndexRequiresUser(): void
    {
        $controller = $this->makeController(null);
        $response   = $controller->index();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }
}
