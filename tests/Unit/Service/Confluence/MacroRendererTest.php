<?php

/**
 * MacroRendererTest
 *
 * Unit tests for {@see \OCA\MyDash\Service\Confluence\MacroRenderer}
 * covering REQ-CFLI-006 (recognised + fallback macro handling).
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

use OCA\MyDash\Service\Confluence\MacroRenderer;
use PHPUnit\Framework\TestCase;

class MacroRendererTest extends TestCase
{

    private MacroRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new MacroRenderer();
    }

    public function testCodeMacroBecomesPreCodeBlock(): void
    {
        $html   = '<ac:structured-macro ac:name="code"><ac:parameter ac:name="language">java</ac:parameter><ac:plain-text-body><![CDATA[System.out.println("hi");]]></ac:plain-text-body></ac:structured-macro>';
        $result = $this->renderer->render(html: $html);
        $this->assertStringContainsString(needle: '<pre>', haystack: $result);
        $this->assertStringContainsString(needle: 'class="language-java"', haystack: $result);
        $this->assertStringContainsString(needle: 'System.out.println', haystack: $result);
    }

    public function testInfoPanelMacroGetsCssClass(): void
    {
        $html   = '<ac:structured-macro ac:name="info"><ac:rich-text-body><p>note body</p></ac:rich-text-body></ac:structured-macro>';
        $result = $this->renderer->render(html: $html);
        $this->assertStringContainsString(needle: 'class="confluence-panel-info"', haystack: $result);
        $this->assertStringContainsString(needle: 'note body', haystack: $result);
    }

    public function testExpandMacroBecomesDetailsBlock(): void
    {
        $html   = '<ac:structured-macro ac:name="expand"><ac:parameter ac:name="title">More</ac:parameter><ac:rich-text-body><p>hidden</p></ac:rich-text-body></ac:structured-macro>';
        $result = $this->renderer->render(html: $html);
        $this->assertStringContainsString(needle: '<details>', haystack: $result);
        $this->assertStringContainsString(needle: '<summary>More</summary>', haystack: $result);
        $this->assertStringContainsString(needle: 'hidden', haystack: $result);
    }

    public function testUnknownMacroFallsBackToUnsupportedPlaceholder(): void
    {
        $html   = '<ac:structured-macro ac:name="sql"><ac:plain-text-body><![CDATA[SELECT 1]]></ac:plain-text-body></ac:structured-macro>';
        $result = $this->renderer->render(html: $html);
        $this->assertStringContainsString(needle: 'class="confluence-unsupported-macro"', haystack: $result);
        $this->assertStringContainsString(needle: '<code>sql</code>', haystack: $result);
    }

    public function testAcImageBecomesPlainImg(): void
    {
        $html   = '<ac:image><ri:attachment ri:filename="diagram.png"/></ac:image>';
        $result = $this->renderer->render(html: $html);
        $this->assertStringContainsString(needle: '<img src="diagram.png"', haystack: $result);
    }
}
