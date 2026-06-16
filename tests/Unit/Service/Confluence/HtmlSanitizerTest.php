<?php

/**
 * HtmlSanitizerTest
 *
 * Unit tests for {@see \OCA\MyDash\Service\Confluence\HtmlSanitizer}
 * covering REQ-CFLI-012 (allow-list sanitisation).
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

use OCA\MyDash\Service\Confluence\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{

    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new HtmlSanitizer();
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $this->assertSame(expected: '', actual: $this->sanitizer->sanitize(html: ''));
        $this->assertSame(expected: '', actual: $this->sanitizer->sanitize(html: '   '));
    }

    public function testAllowListedTagsArePreserved(): void
    {
        $html   = '<p>Hello <strong>world</strong> <em>and</em> <a href="/x">link</a></p>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringContainsString(needle: '<p>', haystack: $result);
        $this->assertStringContainsString(needle: '<strong>world</strong>', haystack: $result);
        $this->assertStringContainsString(needle: '<a href="/x">link</a>', haystack: $result);
    }

    public function testScriptTagIsStripped(): void
    {
        $html   = '<p>Safe <script>alert(1)</script> text</p>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringNotContainsString(needle: '<script', haystack: $result);
        $this->assertStringNotContainsString(needle: 'alert(1)', haystack: $result);
        $this->assertStringContainsString(needle: 'Safe', haystack: $result);
        $this->assertStringContainsString(needle: 'text', haystack: $result);
    }

    public function testEventHandlersAreStripped(): void
    {
        $html   = '<a href="/x" onclick="alert(2)">x</a>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringNotContainsString(needle: 'onclick', haystack: $result);
        $this->assertStringContainsString(needle: 'href="/x"', haystack: $result);
    }

    public function testJavascriptUrlsAreStripped(): void
    {
        $html   = '<a href="javascript:alert(1)">x</a>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringNotContainsString(needle: 'javascript:', haystack: $result);
    }

    public function testDataUriXssIsStripped(): void
    {
        $html   = '<a href="data:text/html,<script>alert(1)</script>">x</a>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringNotContainsString(needle: 'data:', haystack: $result);
    }

    public function testVbscriptUrlIsStripped(): void
    {
        $html   = '<a href="vbscript:MsgBox(1)">x</a>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringNotContainsString(needle: 'vbscript:', haystack: $result);
    }

    public function testBlobUrlIsStripped(): void
    {
        $html   = '<a href="blob:https://example.com/abc">x</a>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringNotContainsString(needle: 'blob:', haystack: $result);
    }

    public function testFileUrlIsStripped(): void
    {
        $html   = '<a href="file:///etc/passwd">x</a>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringNotContainsString(needle: 'file://', haystack: $result);
    }

    public function testHttpsUrlIsPreserved(): void
    {
        $html   = '<a href="https://example.com/path">link</a>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringContainsString(needle: 'href="https://example.com/path"', haystack: $result);
    }

    public function testRelativeUrlIsPreserved(): void
    {
        $html   = '<a href="/relative/path">link</a>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringContainsString(needle: 'href="/relative/path"', haystack: $result);
    }

    public function testMailtoUrlIsPreserved(): void
    {
        $html   = '<a href="mailto:info@example.com">mail</a>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringContainsString(needle: 'href="mailto:info@example.com"', haystack: $result);
    }

    public function testTablesAndPreformattedBlocksAreKept(): void
    {
        $html   = '<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>cell</td></tr></tbody></table><pre><code class="language-php">echo 1;</code></pre>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringContainsString(needle: '<table>', haystack: $result);
        $this->assertStringContainsString(needle: '<th>H</th>', haystack: $result);
        $this->assertStringContainsString(needle: '<td>cell</td>', haystack: $result);
        $this->assertStringContainsString(needle: 'class="language-php"', haystack: $result);
    }

    public function testDisallowedSpanIsUnwrappedKeepingText(): void
    {
        // span IS allowed (preserves classNames for macro panels) so use button instead.
        $html   = '<p><button>click</button></p>';
        $result = $this->sanitizer->sanitize(html: $html);
        $this->assertStringNotContainsString(needle: '<button', haystack: $result);
        $this->assertStringContainsString(needle: 'click', haystack: $result);
    }
}
