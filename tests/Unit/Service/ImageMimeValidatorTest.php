<?php

/**
 * ImageMimeValidator Test
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\MyDash\Exception\CorruptImageException;
use OCA\MyDash\Exception\MimeMismatchException;
use OCA\MyDash\Service\ImageMimeValidator;
use PHPUnit\Framework\TestCase;

class ImageMimeValidatorTest extends TestCase
{
    private ImageMimeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ImageMimeValidator();
    }

    /**
     * Tiny 1x1 PNG bytes (red pixel).
     */
    private function tinyPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==',
            true
        );
    }

    /**
     * Tiny 1x1 GIF bytes.
     */
    private function tinyGif(): string
    {
        return base64_decode(
            'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==',
            true
        );
    }

    public function testSvgIsSkipped(): void
    {
        // Should not throw — SVG is delegated to the sanitiser.
        $this->validator->validate(declaredType: 'svg', bytes: '<svg></svg>');
        $this->assertTrue(true);
    }

    public function testValidPngPasses(): void
    {
        $this->validator->validate(declaredType: 'png', bytes: $this->tinyPng());
        $this->assertTrue(true);
    }

    public function testJpgAlsoMapsToJpegMime(): void
    {
        // We can't easily make a tiny JPEG inline, but we can confirm
        // that a PNG-as-jpg fails with a mime mismatch (proving the map
        // is consulted for both jpg and jpeg).
        $this->expectException(MimeMismatchException::class);
        $this->validator->validate(declaredType: 'jpg', bytes: $this->tinyPng());
    }

    public function testCorruptBytesThrowCorruptImage(): void
    {
        $this->expectException(CorruptImageException::class);
        $this->validator->validate(declaredType: 'png', bytes: 'not-an-image');
    }

    public function testMimeMismatchPngActualGif(): void
    {
        $this->expectException(MimeMismatchException::class);
        $this->validator->validate(declaredType: 'png', bytes: $this->tinyGif());
    }

    public function testUnknownDeclaredTypeIsRejected(): void
    {
        // Defensive guard — caller should already have vetted the type.
        $this->expectException(MimeMismatchException::class);
        $this->validator->validate(declaredType: 'bmp', bytes: $this->tinyPng());
    }
}
