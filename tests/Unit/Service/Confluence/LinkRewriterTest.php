<?php

/**
 * LinkRewriterTest
 *
 * Unit tests for {@see \OCA\MyDash\Service\Confluence\LinkRewriter}
 * covering REQ-CFLI-004 (internal-link rewriting + external preservation).
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

use OCA\MyDash\Service\Confluence\LinkRewriter;
use PHPUnit\Framework\TestCase;

class LinkRewriterTest extends TestCase
{

    private LinkRewriter $rewriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rewriter = new LinkRewriter();
    }

    public function testInternalLinkIsRewrittenToDashboardDeepLink(): void
    {
        $html = '<a href="page-456.html">See Also</a>';
        $map  = ['page-456' => 'uuid-456'];
        $res  = $this->rewriter->rewrite(html: $html, pageIdToUuid: $map);
        $this->assertStringContainsString(
            needle: '<a href="/apps/mydash/dashboard/uuid-456">',
            haystack: $res['html']
        );
        $this->assertSame(expected: [], actual: $res['warnings']);
    }

    public function testCrossSpaceLinkIsRewritten(): void
    {
        $html = '<a href="OTHER-SPACE/page-789.html">External</a>';
        $map  = ['page-789' => 'uuid-789'];
        $res  = $this->rewriter->rewrite(html: $html, pageIdToUuid: $map);
        $this->assertStringContainsString(
            needle: '/apps/mydash/dashboard/uuid-789',
            haystack: $res['html']
        );
    }

    public function testHttpLinksAreLeftUntouched(): void
    {
        $html = '<a href="https://example.com">External</a>';
        $res  = $this->rewriter->rewrite(html: $html, pageIdToUuid: []);
        $this->assertStringContainsString(
            needle: 'href="https://example.com"',
            haystack: $res['html']
        );
        $this->assertSame(expected: [], actual: $res['warnings']);
    }

    public function testMissingPageProducesWarningAndKeepsHref(): void
    {
        $html = '<a href="page-999.html">Missing</a>';
        $res  = $this->rewriter->rewrite(html: $html, pageIdToUuid: []);
        $this->assertStringContainsString(
            needle: 'href="page-999.html"',
            haystack: $res['html']
        );
        $this->assertCount(expectedCount: 1, haystack: $res['warnings']);
        $this->assertStringContainsString(
            needle: 'page-999.html',
            haystack: $res['warnings'][0]
        );
    }
}
