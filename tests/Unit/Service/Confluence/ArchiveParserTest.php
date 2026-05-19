<?php

/**
 * ArchiveParserTest
 *
 * Unit tests for {@see \OCA\MyDash\Service\Confluence\ArchiveParser}
 * covering REQ-CFLI-001 (archive structure), REQ-CFLI-002 (hierarchy
 * extraction), REQ-CFLI-003 (body selector waterfall).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service\Confluence
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service\Confluence;

use InvalidArgumentException;
use OCA\MyDash\Service\Confluence\ArchiveParser;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ArchiveParserTest extends TestCase
{

    /** @var array<int, string> Temporary files created by the tests. */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            if (file_exists(filename: $path) === true) {
                @unlink(filename: $path);
            }
        }

        parent::tearDown();
    }

    public function testMissingIndexHtmlIsRejected(): void
    {
        $zipPath = $this->makeZip(entries: ['SPACE/page-1.html' => '<html><body>x</body></html>']);
        $parser  = new ArchiveParser();

        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'index.html not found');
        $parser->parse(zipPath: $zipPath);
    }

    public function testParsesPagesAndExtractsBody(): void
    {
        $index = '<html><body><ul>'
            .'<li><a href="SPACE/page-1.html">P1</a></li>'
            .'<li><a href="SPACE/page-2.html">P2</a></li>'
            .'</ul></body></html>';

        $page1 = '<html><head><title>SPACE : Architecture</title></head><body>'
            .'<div id="main-content"><p>Body of P1</p></div>'
            .'</body></html>';

        $page2 = '<html><head><title>SPACE : Q1 Goals</title></head><body>'
            .'<ol class="breadcrumbs"><li><a href="page-1.html">P1</a></li><li>P2</li></ol>'
            .'<div id="main-content"><p>Body of P2</p></div>'
            .'</body></html>';

        $zipPath = $this->makeZip(entries: [
            'index.html'        => $index,
            'SPACE/page-1.html' => $page1,
            'SPACE/page-2.html' => $page2,
        ]);

        $parser  = new ArchiveParser();
        $archive = $parser->parse(zipPath: $zipPath);

        $this->assertSame(expected: 2, actual: $archive->pageCount());

        $byPageId = [];
        foreach ($archive->pages as $page) {
            $byPageId[$page->pageId] = $page;
        }

        $this->assertArrayHasKey(key: 'page-1', array: $byPageId);
        $this->assertArrayHasKey(key: 'page-2', array: $byPageId);

        $this->assertStringContainsString(needle: 'Body of P1', haystack: $byPageId['page-1']->body);
        $this->assertSame(expected: 'Architecture', actual: $byPageId['page-1']->title);
        $this->assertNull(actual: $byPageId['page-1']->parentPageId);

        $this->assertStringContainsString(needle: 'Body of P2', haystack: $byPageId['page-2']->body);
        $this->assertSame(expected: 'Q1 Goals', actual: $byPageId['page-2']->title);
        $this->assertSame(expected: 'page-1', actual: $byPageId['page-2']->parentPageId);
    }

    public function testAttachmentsAndImagesAreCollected(): void
    {
        $zipPath = $this->makeZip(entries: [
            'index.html'                       => '<html><body><a href="SPACE/page-1.html">x</a></body></html>',
            'SPACE/page-1.html'                => '<html><body><div id="main-content">x</div></body></html>',
            'attachments/page-1/diagram.png'   => "PNGDATA",
            'images/icon.png'                  => "PNGDATA2",
        ]);

        $parser  = new ArchiveParser();
        $archive = $parser->parse(zipPath: $zipPath);

        $this->assertCount(expectedCount: 1, haystack: $archive->attachments);
        $this->assertSame(
            expected: 'attachments/page-1/diagram.png',
            actual: $archive->attachments[0]
        );
        $this->assertCount(expectedCount: 1, haystack: $archive->images);
        $this->assertSame(expected: 'images/icon.png', actual: $archive->images[0]);
        $this->assertSame(expected: 2, actual: $archive->attachmentCount());
    }

    /**
     * Build a temp ZIP with the given entries and remember it for cleanup.
     *
     * @param array<string, string> $entries Path → bytes.
     */
    private function makeZip(array $entries): string
    {
        $path = (string) tempnam(directory: sys_get_temp_dir(), prefix: 'cflitest_');
        @unlink(filename: $path);
        $path .= '.zip';
        $this->temp[] = $path;

        $zip = new ZipArchive();
        $zip->open(filename: $path, flags: (ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $body) {
            $zip->addFromString(name: $name, content: $body);
        }

        $zip->close();
        return $path;
    }
}
