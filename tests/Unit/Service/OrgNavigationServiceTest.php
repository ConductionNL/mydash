<?php

/**
 * OrgNavigationServiceTest
 *
 * Unit tests for the org-wide navigation editor service
 * (REQ-ONAV-001..012).
 *
 * Covers:
 *   - Validation: depth limit, UUID id format, duplicate ids, label
 *     required, URL scheme rejection (`javascript:`, `data:`,
 *     `vbscript:`), groupVisibility shape.
 *   - Group filtering: null = all-visible, array = restrict to members,
 *     hidden parent cascades to children, multi-group OR semantics.
 *   - Storage round-trip via mocked IAppData (folder + file).
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

use InvalidArgumentException;
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\OrgNavigationService;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the org-wide navigation editor service.
 */
class OrgNavigationServiceTest extends TestCase
{

    /** @var IAppData&MockObject */
    private $appData;

    /** @var AdminTemplateService&MockObject */
    private $templateService;

    private OrgNavigationService $service;


    protected function setUp(): void
    {
        $this->appData         = $this->createMock(IAppData::class);
        $this->templateService = $this->createMock(AdminTemplateService::class);

        $this->service = new OrgNavigationService(
            appData: $this->appData,
            templateService: $this->templateService,
        );

    }//end setUp()


    /**
     * Build a deterministic UUID v4 derived from the given seed so
     * fixtures stay readable.
     *
     * @param string $seed The seed.
     *
     * @return string A canonical UUID string.
     */
    private function uuid(string $seed): string
    {
        $hash = md5($seed);
        return sprintf(
            '%s-%s-4%s-8%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 3),
            substr($hash, 15, 3),
            substr($hash, 18, 12)
        );

    }//end uuid()


    public function testValidateAcceptsWellFormedTree(): void
    {
        $tree = [
            [
                'id'              => $this->uuid('a'),
                'label'           => 'Section A',
                'icon'            => 'folder',
                'url'             => null,
                'openInNewTab'    => false,
                'groupVisibility' => null,
                'children'        => [
                    [
                        'id'       => $this->uuid('a.1'),
                        'label'    => 'Child',
                        'url'      => '/apps/mydash/dashboards',
                        'children' => [],
                    ],
                ],
            ],
        ];

        $this->service->validateTree(tree: $tree);
        $this->assertTrue(true);

    }//end testValidateAcceptsWellFormedTree()


    public function testValidateRejectsTreeExceedingDepth(): void
    {
        $tree = [
            [
                'id'       => $this->uuid('l1'),
                'label'    => 'L1',
                'children' => [
                    [
                        'id'       => $this->uuid('l2'),
                        'label'    => 'L2',
                        'children' => [
                            [
                                'id'       => $this->uuid('l3'),
                                'label'    => 'L3',
                                'children' => [
                                    [
                                        'id'       => $this->uuid('l4'),
                                        'label'    => 'L4 too deep',
                                        'children' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tree depth cannot exceed 3 levels');
        $this->service->validateTree(tree: $tree);

    }//end testValidateRejectsTreeExceedingDepth()


    public function testValidateRejectsDuplicateIds(): void
    {
        $shared = $this->uuid('shared');
        $tree   = [
            ['id' => $shared, 'label' => 'A'],
            ['id' => $shared, 'label' => 'B'],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicate.*id/i');
        $this->service->validateTree(tree: $tree);

    }//end testValidateRejectsDuplicateIds()


    public function testValidateRejectsJavascriptUrl(): void
    {
        $tree = [
            ['id' => $this->uuid('x'), 'label' => 'X', 'url' => 'JavaScript:alert(1)'],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('URL scheme is not allowed');
        $this->service->validateTree(tree: $tree);

    }//end testValidateRejectsJavascriptUrl()


    public function testValidateRejectsDataUrl(): void
    {
        $tree = [
            ['id' => $this->uuid('x'), 'label' => 'X', 'url' => 'data:text/html,<script>alert(1)</script>'],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->service->validateTree(tree: $tree);

    }//end testValidateRejectsDataUrl()


    public function testValidateRejectsEmptyLabel(): void
    {
        $tree = [
            ['id' => $this->uuid('x'), 'label' => '   '],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('label is required');
        $this->service->validateTree(tree: $tree);

    }//end testValidateRejectsEmptyLabel()


    public function testValidateRejectsNonUuidId(): void
    {
        $tree = [
            ['id' => 'not-a-uuid', 'label' => 'X'],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Node id must be a valid UUID');
        $this->service->validateTree(tree: $tree);

    }//end testValidateRejectsNonUuidId()


    public function testFilterReturnsFullTreeWhenAllNodesAreUnrestricted(): void
    {
        $tree = [
            [
                'id'              => $this->uuid('a'),
                'label'           => 'A',
                'groupVisibility' => null,
                'children'        => [
                    [
                        'id'              => $this->uuid('a.1'),
                        'label'           => 'A.1',
                        'groupVisibility' => null,
                        'children'        => [],
                    ],
                ],
            ],
        ];

        $this->templateService
            ->method('getUserGroupIdsFor')
            ->willReturn(['anyone']);

        $result = $this->service->filterTreeByUserGroups(
            tree: $tree,
            userId: 'alice'
        );

        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]['children']);

    }//end testFilterReturnsFullTreeWhenAllNodesAreUnrestricted()


    public function testFilterHidesNodeWhenUserNotInGroup(): void
    {
        $tree = [
            [
                'id'              => $this->uuid('admin'),
                'label'           => 'Admin only',
                'groupVisibility' => ['admin'],
                'children'        => [],
            ],
            [
                'id'              => $this->uuid('public'),
                'label'           => 'Public',
                'groupVisibility' => null,
                'children'        => [],
            ],
        ];

        $this->templateService
            ->method('getUserGroupIdsFor')
            ->willReturn(['users']);

        $result = $this->service->filterTreeByUserGroups(
            tree: $tree,
            userId: 'bob'
        );

        $this->assertCount(1, $result);
        $this->assertSame('Public', $result[0]['label']);

    }//end testFilterHidesNodeWhenUserNotInGroup()


    public function testFilterShowsNodeWhenUserMatchesAnyListedGroup(): void
    {
        $tree = [
            [
                'id'              => $this->uuid('mkt'),
                'label'           => 'Sales/Marketing',
                'groupVisibility' => ['marketing', 'sales'],
                'children'        => [],
            ],
        ];

        $this->templateService
            ->method('getUserGroupIdsFor')
            ->willReturn(['sales']);

        $result = $this->service->filterTreeByUserGroups(
            tree: $tree,
            userId: 'sam'
        );

        $this->assertCount(1, $result);

    }//end testFilterShowsNodeWhenUserMatchesAnyListedGroup()


    public function testFilterCascadesHiddenParentToChildren(): void
    {
        $tree = [
            [
                'id'              => $this->uuid('p'),
                'label'           => 'Parent',
                'groupVisibility' => ['secret'],
                'children'        => [
                    [
                        'id'              => $this->uuid('c'),
                        'label'           => 'Child',
                        'groupVisibility' => null,
                        'children'        => [],
                    ],
                ],
            ],
        ];

        $this->templateService
            ->method('getUserGroupIdsFor')
            ->willReturn(['users']);

        $result = $this->service->filterTreeByUserGroups(
            tree: $tree,
            userId: 'eve'
        );

        $this->assertSame([], $result);

    }//end testFilterCascadesHiddenParentToChildren()


    public function testGetTreeReturnsEmptyWhenFolderMissing(): void
    {
        $this->appData
            ->method('getFolder')
            ->willThrowException(new NotFoundException());

        $this->assertSame([], $this->service->getTree());

    }//end testGetTreeReturnsEmptyWhenFolderMissing()


    public function testGetTreeReturnsEmptyWhenFileMissing(): void
    {
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')
            ->willThrowException(new NotFoundException());

        $this->appData
            ->method('getFolder')
            ->willReturn($folder);

        $this->assertSame([], $this->service->getTree());

    }//end testGetTreeReturnsEmptyWhenFileMissing()


    public function testGetTreeDecodesPersistedJson(): void
    {
        $payload = json_encode([
            ['id' => $this->uuid('only'), 'label' => 'Only', 'children' => []],
        ]);

        $file = $this->createMock(ISimpleFile::class);
        $file->method('getSize')->willReturn(strlen((string) $payload));
        $file->method('getContent')->willReturn($payload);

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturn($file);

        $this->appData
            ->method('getFolder')
            ->willReturn($folder);

        $tree = $this->service->getTree(language: 'nl');

        $this->assertCount(1, $tree);
        $this->assertSame('Only', $tree[0]['label']);

    }//end testGetTreeDecodesPersistedJson()


    public function testSetTreeWritesNewFileWhenAbsent(): void
    {
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')
            ->willThrowException(new NotFoundException());

        $folder->expects($this->once())
            ->method('newFile')
            ->with(
                $this->equalTo('nl.json'),
                $this->callback(static function (string $content): bool {
                    $decoded = json_decode($content, true);
                    return is_array($decoded) === true
                        && count($decoded) === 1
                        && $decoded[0]['label'] === 'Item';
                })
            );

        $this->appData
            ->method('getFolder')
            ->willReturn($folder);

        $this->service->setTree(
            tree: [
                ['id' => $this->uuid('one'), 'label' => 'Item', 'children' => []],
            ],
            language: 'nl'
        );

    }//end testSetTreeWritesNewFileWhenAbsent()


    public function testSetTreeOverwritesExistingFile(): void
    {
        $file = $this->createMock(ISimpleFile::class);
        $file->expects($this->once())->method('putContent');

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturn($file);

        $this->appData
            ->method('getFolder')
            ->willReturn($folder);

        $this->service->setTree(
            tree: [
                ['id' => $this->uuid('over'), 'label' => 'Over', 'children' => []],
            ],
            language: 'en'
        );

    }//end testSetTreeOverwritesExistingFile()


    public function testSetTreeRejectsInvalidPayload(): void
    {
        $this->appData->expects($this->never())->method('getFolder');

        $this->expectException(InvalidArgumentException::class);
        $this->service->setTree(
            tree: [
                ['id' => 'not-uuid', 'label' => 'X'],
            ],
            language: 'nl'
        );

    }//end testSetTreeRejectsInvalidPayload()


    public function testSanitiseUrlAcceptsHttpsAndRelativePaths(): void
    {
        $this->assertSame(
            'https://example.com/x',
            $this->service->sanitiseUrl(url: 'https://example.com/x')
        );
        $this->assertSame(
            '/apps/mydash/dashboards',
            $this->service->sanitiseUrl(url: '/apps/mydash/dashboards')
        );

    }//end testSanitiseUrlAcceptsHttpsAndRelativePaths()


    public function testSanitiseUrlRejectsVbscript(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->sanitiseUrl(url: 'VBScript:msgbox');

    }//end testSanitiseUrlRejectsVbscript()


}//end class
