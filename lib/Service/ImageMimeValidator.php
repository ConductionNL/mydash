<?php

/**
 * ImageMimeValidator
 *
 * Cross-checks the declared image type from a base64 data URL prefix
 * against the MIME type detected by `getimagesizefromstring` for raster
 * formats (jpeg/jpg/png/gif/webp). SVG is delegated to a separate
 * sanitiser (see `svg-sanitisation` capability) and intentionally NOT
 * validated here.
 *
 * The validator never loads bytes into memory beyond the caller's
 * decoded buffer — the size cap is enforced by the caller before this
 * validator is invoked.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use OCA\MyDash\Exception\CorruptImageException;
use OCA\MyDash\Exception\MimeMismatchException;

/**
 * Validates that declared raster image type matches the detected MIME.
 */
class ImageMimeValidator
{
    /**
     * Map normalised declared types to expected detected MIMEs.
     *
     * `getimagesizefromstring` returns these mime strings for valid
     * images of each type. `jpeg` and `jpg` both produce `image/jpeg`.
     *
     * @var array<string,string>
     */
    private const RASTER_MIME_MAP = [
        'jpeg' => 'image/jpeg',
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    /**
     * Validate that the declared raster type matches the detected MIME.
     *
     * SVG is skipped (delegated to SvgSanitiser in a sibling change).
     * Caller MUST enforce the size cap before invoking this method to
     * avoid loading oversize blobs into the image library.
     *
     * @param string $declaredType Normalised, lowercase declared type
     *                             (e.g. 'png', 'jpg', 'svg').
     * @param string $bytes        Decoded image bytes.
     *
     * @return void
     *
     * @throws CorruptImageException When the bytes cannot be decoded.
     * @throws MimeMismatchException When the detected MIME differs from
     *                               the declared type.
     */
    public function validate(string $declaredType, string $bytes): void
    {
        // SVG validation is the sanitiser's job, not this validator's.
        if ($declaredType === 'svg') {
            return;
        }

        if (isset(self::RASTER_MIME_MAP[$declaredType]) === false) {
            // Should never happen — caller already vetted the declared
            // type — but be defensive against future callers.
            throw new MimeMismatchException();
        }

        $expectedMime = self::RASTER_MIME_MAP[$declaredType];

        // `getimagesizefromstring` returns false for non-images. We
        // suppress the warning via a local error handler instead of
        // the `@` operator (banned by phpmd's ErrorControlOperator
        // rule). The handler is restored before any branch returns.
        $previous = set_error_handler(
            callback: static function (): bool {
                return true;
            }
        );

        try {
            $info = getimagesizefromstring(string: $bytes);
        } finally {
            restore_error_handler();
            unset($previous);
        }

        if ($info === false) {
            throw new CorruptImageException();
        }

        $detectedMime = $info['mime'];
        if ($detectedMime !== $expectedMime) {
            throw new MimeMismatchException();
        }
    }//end validate()
}//end class
