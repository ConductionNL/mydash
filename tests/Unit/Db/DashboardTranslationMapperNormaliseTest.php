<?php

/**
 * DashboardTranslationMapper normalisation test.
 *
 * Covers the static `normaliseLanguageCode()` helper used to canonicalise
 * locale strings before storage / lookup. REQ-DASH-038, design D2.
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

use OCA\MyDash\Db\DashboardTranslationMapper;
use PHPUnit\Framework\TestCase;

class DashboardTranslationMapperNormaliseTest extends TestCase
{
    public function testEmptyReturnsEmpty(): void
    {
        $this->assertSame('', DashboardTranslationMapper::normaliseLanguageCode(raw: ''));
        $this->assertSame('', DashboardTranslationMapper::normaliseLanguageCode(raw: '   '));
    }

    public function testBareCodeIsLowercased(): void
    {
        $this->assertSame('nl', DashboardTranslationMapper::normaliseLanguageCode(raw: 'nl'));
        $this->assertSame('en', DashboardTranslationMapper::normaliseLanguageCode(raw: 'EN'));
        $this->assertSame('de', DashboardTranslationMapper::normaliseLanguageCode(raw: 'De'));
    }

    public function testPosixUnderscoreIsTruncated(): void
    {
        $this->assertSame('nl', DashboardTranslationMapper::normaliseLanguageCode(raw: 'nl_NL'));
        $this->assertSame('en', DashboardTranslationMapper::normaliseLanguageCode(raw: 'en_US'));
        $this->assertSame('fr', DashboardTranslationMapper::normaliseLanguageCode(raw: 'fr_FR'));
    }

    public function testBcp47HyphenIsTruncated(): void
    {
        $this->assertSame('nl', DashboardTranslationMapper::normaliseLanguageCode(raw: 'nl-BE'));
        $this->assertSame('de', DashboardTranslationMapper::normaliseLanguageCode(raw: 'de-DE'));
        $this->assertSame('en', DashboardTranslationMapper::normaliseLanguageCode(raw: 'EN-GB'));
    }

    public function testWhitespaceTrimmed(): void
    {
        $this->assertSame('nl', DashboardTranslationMapper::normaliseLanguageCode(raw: '  nl_NL  '));
    }
}
