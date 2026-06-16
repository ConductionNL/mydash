<?php

/**
 * SlugGenerator Test
 *
 * Covers the URL-safe slug helper introduced by REQ-DASH-024.
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

use OCA\MyDash\Service\SlugGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see SlugGenerator}.
 */
class SlugGeneratorTest extends TestCase
{
    /**
     * Standard alphanumeric name → simple lowercase slug.
     *
     * @return void
     */
    public function testSlugifyLowercases(): void
    {
        $this->assertSame('marketing', SlugGenerator::slugify(name: 'Marketing'));
    }//end testSlugifyLowercases()

    /**
     * Multi-word names → dash-joined.
     *
     * @return void
     */
    public function testSlugifySpacesBecomeDashes(): void
    {
        $this->assertSame(
            'q1-campaigns',
            SlugGenerator::slugify(name: 'Q1 Campaigns')
        );
    }//end testSlugifySpacesBecomeDashes()

    /**
     * Punctuation outside the grammar is stripped.
     *
     * @return void
     */
    public function testSlugifyStripsPunctuation(): void
    {
        $this->assertSame(
            'hello-world',
            SlugGenerator::slugify(name: 'Hello, World!')
        );
    }//end testSlugifyStripsPunctuation()

    /**
     * Repeated separators collapse to one dash.
     *
     * @return void
     */
    public function testSlugifyCollapsesRepeatedDashes(): void
    {
        $this->assertSame(
            'foo-bar',
            SlugGenerator::slugify(name: 'foo --- bar')
        );
    }//end testSlugifyCollapsesRepeatedDashes()

    /**
     * Names that yield no legal characters → empty slug.
     *
     * @return void
     */
    public function testSlugifyReturnsEmptyOnNoLegalChars(): void
    {
        $this->assertSame('', SlugGenerator::slugify(name: '!!!'));
    }//end testSlugifyReturnsEmptyOnNoLegalChars()

    /**
     * Slug exceeding 128 characters → truncated.
     *
     * @return void
     */
    public function testSlugifyTruncatesLongInput(): void
    {
        $longName = str_repeat('a', 200);
        $slug     = SlugGenerator::slugify(name: $longName);

        $this->assertLessThanOrEqual(SlugGenerator::MAX_LENGTH, strlen($slug));
    }//end testSlugifyTruncatesLongInput()

    /**
     * isValid: legal grammar accepted.
     *
     * @return void
     */
    public function testIsValidAcceptsLegalSlugs(): void
    {
        $this->assertTrue(SlugGenerator::isValid(slug: 'q1-campaigns'));
        $this->assertTrue(SlugGenerator::isValid(slug: 'snake_case'));
        $this->assertTrue(SlugGenerator::isValid(slug: 'abc123'));
    }//end testIsValidAcceptsLegalSlugs()

    /**
     * isValid: empty / uppercase / punctuation rejected.
     *
     * @return void
     */
    public function testIsValidRejectsIllegalSlugs(): void
    {
        $this->assertFalse(SlugGenerator::isValid(slug: ''));
        $this->assertFalse(SlugGenerator::isValid(slug: 'Q1'));
        $this->assertFalse(SlugGenerator::isValid(slug: 'q1 campaigns'));
        $this->assertFalse(SlugGenerator::isValid(slug: 'q1!'));
    }//end testIsValidRejectsIllegalSlugs()

    /**
     * isValid: 128-character cap enforced.
     *
     * @return void
     */
    public function testIsValidRejectsOverLengthSlugs(): void
    {
        $this->assertFalse(
            SlugGenerator::isValid(slug: str_repeat('a', 129))
        );
    }//end testIsValidRejectsOverLengthSlugs()
}//end class
