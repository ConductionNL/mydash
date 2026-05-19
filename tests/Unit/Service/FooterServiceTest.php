<?php

/**
 * FooterServiceTest
 *
 * Covers the footer-customization service (REQ-FTR-001..010): the
 * sanitiser allow-list, the structured-config schema validator, the
 * global-settings round-trip, the colour-string validator, and the
 * per-dashboard `resolveFooterForDashboard()` resolution.
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
use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Service\FooterService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FooterServiceTest extends TestCase
{
    /** @var AdminSettingMapper&MockObject */
    private $settingMapper;

    private FooterService $service;

    protected function setUp(): void
    {
        $this->settingMapper = $this->createMock(AdminSettingMapper::class);
        $this->service       = new FooterService(settingMapper: $this->settingMapper);
    }

    // ----- REQ-FTR-005: HTML sanitisation -----

    public function testSanitiseStripsForbiddenTags(): void
    {
        $out = $this->service->sanitiseHtml(html: '<div>Not allowed</div><p>Allowed</p>');
        $this->assertStringNotContainsString('<div>', $out);
        $this->assertStringContainsString('<p>Allowed</p>', $out);
    }

    public function testSanitiseStripsScriptTagAndContent(): void
    {
        $out = $this->service->sanitiseHtml(
            html: '<p>Text <script>alert("xss")</script> here</p>'
        );
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function testSanitiseStripsEventHandlerAttributes(): void
    {
        $out = $this->service->sanitiseHtml(html: '<p onclick="evil()">Click</p>');
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringContainsString('Click', $out);
    }

    public function testSanitiseStripsClassAndDataAttributes(): void
    {
        $out = $this->service->sanitiseHtml(html: "<p class='danger' data-x='y'>Text</p>");
        $this->assertStringNotContainsString('class=', $out);
        $this->assertStringNotContainsString('data-x', $out);
    }

    public function testSanitiseStripsStyleAttribute(): void
    {
        $out = $this->service->sanitiseHtml(html: "<p style='color:red'>Hi</p>");
        $this->assertStringNotContainsString('style', $out);
    }

    public function testSanitiseExternalLinkAddsRelAndTarget(): void
    {
        $out = $this->service->sanitiseHtml(
            html: '<a href="https://example.com">Link</a>'
        );
        $this->assertStringContainsString('rel="noopener noreferrer"', $out);
        $this->assertStringContainsString('target="_blank"', $out);
        $this->assertStringContainsString('href="https://example.com"', $out);
    }

    public function testSanitiseImgKeepsSrcOnly(): void
    {
        $out = $this->service->sanitiseHtml(
            html: '<img src="https://example.com/logo.png" alt="logo" onerror="bad()" />'
        );
        $this->assertStringContainsString('src="https://example.com/logo.png"', $out);
        $this->assertStringNotContainsString('alt=', $out);
        $this->assertStringNotContainsString('onerror', $out);
    }

    public function testSanitiseImgRejectsDataUri(): void
    {
        $out = $this->service->sanitiseHtml(
            html: '<img src="data:image/png;base64,AAAA" />'
        );
        $this->assertStringNotContainsString('data:', $out);
    }

    public function testSanitiseRejectsOversizedHtml(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $oversize = str_repeat(string: 'a', times: (FooterService::MAX_HTML_BYTES + 1));
        $this->service->sanitiseHtml(html: $oversize);
    }

    public function testSanitisePreservesNestedTags(): void
    {
        $out = $this->service->sanitiseHtml(
            html: '<strong><em>Bold and italic</em></strong>'
        );
        $this->assertStringContainsString('<strong>', $out);
        $this->assertStringContainsString('<em>', $out);
        $this->assertStringContainsString('</em>', $out);
        $this->assertStringContainsString('</strong>', $out);
    }

    // ----- REQ-FTR-003: Structured config validation -----

    public function testStructuredConfigAcceptsKnownKeys(): void
    {
        // No exception expected.
        $this->service->validateStructuredConfig(config: [
            'logoUrl'       => 'https://e.com/l.png',
            'organisation'  => 'ACME',
            'address'       => 'Main 1',
            'links'         => [['label' => 'Privacy', 'url' => 'https://e.com/p']],
            'legal'         => 'All rights reserved',
            'copyrightYear' => 2026,
            'layoutMode'    => 'columns',
        ]);
        $this->assertTrue(true);
    }

    public function testStructuredConfigRejectsUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateStructuredConfig(config: ['unknownField' => 'x']);
    }

    public function testStructuredConfigRejectsBadLayoutMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateStructuredConfig(config: ['layoutMode' => 'spiral']);
    }

    public function testStructuredConfigRejectsMalformedLink(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->validateStructuredConfig(config: [
            'links' => [['label' => 'Privacy']],
        ]);
    }

    public function testStructuredConfigAcceptsInlineLayout(): void
    {
        $this->service->validateStructuredConfig(config: [
            'organisation' => 'ACME',
            'layoutMode'   => 'inline',
        ]);
        $this->assertTrue(true);
    }

    // ----- REQ-FTR-001/010: getGlobalSettings -----

    public function testGetGlobalSettingsReturnsDefaults(): void
    {
        $this->settingMapper->method('getValue')->willReturn(null);

        $settings = $this->service->getGlobalSettings();

        $this->assertFalse($settings['footerEnabled']);
        $this->assertSame('', $settings['footerHtml']);
        $this->assertSame([], $settings['footerConfig']);
        $this->assertNull($settings['footerBackgroundColor']);
        $this->assertNull($settings['footerTextColor']);
    }

    public function testGetGlobalSettingsReturnsStoredValues(): void
    {
        $valueMap = [
            [AdminSetting::KEY_FOOTER_ENABLED, false, true],
            [AdminSetting::KEY_FOOTER_HTML, '', '<p>Footer</p>'],
            [AdminSetting::KEY_FOOTER_CONFIG, [], ['organisation' => 'ACME']],
            [AdminSetting::KEY_FOOTER_BACKGROUND_COLOR, null, '#1a1a1a'],
            [AdminSetting::KEY_FOOTER_TEXT_COLOR, null, '#ffffff'],
        ];
        $this->settingMapper->method('getValue')->willReturnMap($valueMap);

        $settings = $this->service->getGlobalSettings();

        $this->assertTrue($settings['footerEnabled']);
        $this->assertSame('<p>Footer</p>', $settings['footerHtml']);
        $this->assertSame(['organisation' => 'ACME'], $settings['footerConfig']);
        $this->assertSame('#1a1a1a', $settings['footerBackgroundColor']);
        $this->assertSame('#ffffff', $settings['footerTextColor']);
    }

    // ----- REQ-FTR-002/009: updateGlobalSettings sanitises + validates -----

    public function testUpdateGlobalSettingsPersistsBoolToggle(): void
    {
        $this->settingMapper
            ->expects($this->once())
            ->method('setSetting')
            ->with(AdminSetting::KEY_FOOTER_ENABLED, true);

        $this->service->updateGlobalSettings(patch: ['footerEnabled' => true]);
    }

    public function testUpdateGlobalSettingsSanitisesHtmlBeforeSave(): void
    {
        $captured = null;
        $this->settingMapper
            ->expects($this->once())
            ->method('setSetting')
            ->with(
                $this->equalTo(AdminSetting::KEY_FOOTER_HTML),
                $this->callback(function ($v) use (&$captured) {
                    $captured = $v;
                    return true;
                })
            );

        $this->service->updateGlobalSettings(patch: [
            'footerHtml' => '<p onclick="bad">Hi</p><script>x</script>',
        ]);

        $this->assertIsString($captured);
        $this->assertStringNotContainsString('onclick', $captured);
        $this->assertStringNotContainsString('<script', $captured);
        $this->assertStringContainsString('<p>Hi</p>', $captured);
    }

    public function testUpdateGlobalSettingsRejectsOversizeHtmlWith413(): void
    {
        $this->settingMapper->expects($this->never())->method('setSetting');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('8 KB');

        $this->service->updateGlobalSettings(patch: [
            'footerHtml' => str_repeat(string: 'a', times: (FooterService::MAX_HTML_BYTES + 1)),
        ]);
    }

    public function testUpdateGlobalSettingsRejectsBadColour(): void
    {
        $this->settingMapper->expects($this->never())->method('setSetting');
        $this->expectException(InvalidArgumentException::class);

        $this->service->updateGlobalSettings(patch: [
            'footerBackgroundColor' => 'not-a-colour',
        ]);
    }

    public function testUpdateGlobalSettingsAllowsNullColour(): void
    {
        $this->settingMapper
            ->expects($this->once())
            ->method('setSetting')
            ->with(AdminSetting::KEY_FOOTER_TEXT_COLOR, null);

        $this->service->updateGlobalSettings(patch: ['footerTextColor' => null]);
    }

    public function testUpdateGlobalSettingsRejectsConfigWithUnknownKey(): void
    {
        $this->settingMapper->expects($this->never())->method('setSetting');
        $this->expectException(InvalidArgumentException::class);

        $this->service->updateGlobalSettings(patch: [
            'footerConfig' => ['organisation' => 'ACME', 'foo' => 'bar'],
        ]);
    }

    public function testUpdateGlobalSettingsAcceptsLanguageVariantHtml(): void
    {
        $captured = null;
        $this->settingMapper
            ->expects($this->once())
            ->method('setSetting')
            ->with(
                $this->equalTo(AdminSetting::KEY_FOOTER_HTML),
                $this->callback(function ($v) use (&$captured) {
                    $captured = $v;
                    return true;
                })
            );

        $this->service->updateGlobalSettings(patch: [
            'footerHtml' => ['en' => '<p>EN</p>', 'nl' => '<p>NL</p>'],
        ]);

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('en', $captured);
        $this->assertArrayHasKey('nl', $captured);
    }

    // ----- REQ-FTR-006: per-dashboard resolution -----

    public function testResolveReturnsNullWhenDashboardHidesFooter(): void
    {
        $dash = new Dashboard();
        $dash->setDashboardFooterMode(Dashboard::FOOTER_MODE_HIDDEN);

        $this->settingMapper->expects($this->never())->method('getValue');

        $this->assertNull($this->service->resolveFooterForDashboard(dashboard: $dash));
    }

    public function testResolveReturnsCustomHtmlWhenModeIsCustom(): void
    {
        $dash = new Dashboard();
        $dash->setDashboardFooterMode(Dashboard::FOOTER_MODE_CUSTOM);
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dash->setDashboardFooterHtml('<p>Mine</p>');

        // Even global-disabled, custom MUST render.
        $this->settingMapper->method('getValue')->willReturn(null);

        $resolved = $this->service->resolveFooterForDashboard(dashboard: $dash);
        $this->assertNotNull($resolved);
        $this->assertSame(Dashboard::FOOTER_MODE_CUSTOM, $resolved['mode']);
        $this->assertSame('<p>Mine</p>', $resolved['html']);
    }

    public function testResolveReturnsNullWhenInheritAndGlobalDisabled(): void
    {
        $dash = new Dashboard();
        $dash->setDashboardFooterMode(Dashboard::FOOTER_MODE_INHERIT);

        $this->settingMapper->method('getValue')->willReturn(null);

        $this->assertNull($this->service->resolveFooterForDashboard(dashboard: $dash));
    }

    public function testResolveReturnsGlobalHtmlWhenInheritAndEnabled(): void
    {
        $dash = new Dashboard();
        $dash->setDashboardFooterMode(Dashboard::FOOTER_MODE_INHERIT);

        $valueMap = [
            [AdminSetting::KEY_FOOTER_ENABLED, false, true],
            [AdminSetting::KEY_FOOTER_HTML, '', '<p>Global</p>'],
            [AdminSetting::KEY_FOOTER_CONFIG, [], []],
            [AdminSetting::KEY_FOOTER_BACKGROUND_COLOR, null, null],
            [AdminSetting::KEY_FOOTER_TEXT_COLOR, null, null],
        ];
        $this->settingMapper->method('getValue')->willReturnMap($valueMap);

        $resolved = $this->service->resolveFooterForDashboard(dashboard: $dash);
        $this->assertNotNull($resolved);
        $this->assertSame('global', $resolved['mode']);
        $this->assertSame('<p>Global</p>', $resolved['html']);
    }

    public function testResolveReturnsNullWhenInheritEnabledButEmptyContent(): void
    {
        $dash = new Dashboard();
        $dash->setDashboardFooterMode(Dashboard::FOOTER_MODE_INHERIT);

        $valueMap = [
            [AdminSetting::KEY_FOOTER_ENABLED, false, true],
            [AdminSetting::KEY_FOOTER_HTML, '', ''],
            [AdminSetting::KEY_FOOTER_CONFIG, [], []],
            [AdminSetting::KEY_FOOTER_BACKGROUND_COLOR, null, null],
            [AdminSetting::KEY_FOOTER_TEXT_COLOR, null, null],
        ];
        $this->settingMapper->method('getValue')->willReturnMap($valueMap);

        $this->assertNull($this->service->resolveFooterForDashboard(dashboard: $dash));
    }
}
