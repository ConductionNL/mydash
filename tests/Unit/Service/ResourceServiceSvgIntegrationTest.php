<?php

/**
 * ResourceService SVG Integration Test
 *
 * Wires the real `SvgSanitiser` into a real `ResourceService` against a
 * mocked `IAppData` so we can verify the integration scenarios from
 * tasks 4.1..4.5 of the svg-sanitisation change (REQ-RES-009..013):
 *
 * - malicious `<script>` SVG → upload succeeds; persisted bytes have
 *   no `<script>` (4.1)
 * - garbage non-XML payload → InvalidSvgException; no file written (4.2)
 * - 5.5 MB SVG that sanitises down to ≤5 MB → upload succeeds (4.3)
 * - 4.9 MB SVG that sanitises down to ≤4.9 MB → upload succeeds (4.4)
 * - persisted content equals sanitised bytes, NOT original (4.5)
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

use OCA\MyDash\Exception\InvalidSvgException;
use OCA\MyDash\Service\ImageMimeValidator;
use OCA\MyDash\Service\ResourceService;
use OCA\MyDash\Service\SvgSanitiser;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ResourceServiceSvgIntegrationTest extends TestCase
{
    private ResourceService $service;

    /** @var IAppData&MockObject */
    private $appData;

    /** @var ISimpleFolder&MockObject */
    private $folder;

    /** Captures whatever bytes the service writes to disk. */
    private string $persistedBytes = '';

    protected function setUp(): void
    {
        $this->appData = $this->createMock(IAppData::class);
        $this->folder  = $this->createMock(ISimpleFolder::class);
        $this->appData->method('getFolder')->willReturn($this->folder);

        $this->folder->method('newFile')->willReturnCallback(
            function (string $name, $content): ISimpleFile {
                $this->persistedBytes = (string) $content;
                return $this->createMock(ISimpleFile::class);
            }
        );

        $this->service = new ResourceService(
            appData: $this->appData,
            mimeValidator: new ImageMimeValidator(),
            svgSanitiser: new SvgSanitiser(),
        );
    }

    private function dataUrl(string $bytes): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($bytes);
    }

    public function testMaliciousSvgUploadStripsScriptAndPersists(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
             . '<script>alert(1)</script>'
             . '<circle r="5"/>'
             . '</svg>';

        $result = $this->service->upload(
            base64DataUrl: $this->dataUrl($svg)
        );

        $this->assertStringStartsWith('/apps/mydash/resource/resource_', $result['url']);
        $this->assertStringNotContainsString('<script', $this->persistedBytes);
        $this->assertStringNotContainsString('alert', $this->persistedBytes);
        $this->assertStringContainsString('<circle', $this->persistedBytes);
    }

    public function testGarbagePayloadThrowsInvalidSvg(): void
    {
        $this->folder->expects($this->never())->method('newFile');

        $this->expectException(InvalidSvgException::class);
        $this->service->upload(
            base64DataUrl: $this->dataUrl('<not xml')
        );
    }

    public function testInvalidSvgExceptionCarriesStableErrorCode(): void
    {
        try {
            $this->service->upload(
                base64DataUrl: $this->dataUrl('<not xml')
            );
            $this->fail('Expected InvalidSvgException');
        } catch (InvalidSvgException $exception) {
            $this->assertSame('invalid_svg', $exception->getErrorCode());
            $this->assertSame(400, $exception->getHttpStatus());
        }
    }

    public function testOversizeSvgBelowCapAfterSanitisationIsAccepted(): void
    {
        // Build an SVG whose original bytes exceed 5 MB but whose
        // sanitised form (script stripped) drops below the cap. Pad
        // a stripped-out <script> block with ~5.4 MB of payload then
        // append a tiny circle.
        $payload = str_repeat('A', (int) (5.4 * 1024 * 1024));
        $svg     = '<svg xmlns="http://www.w3.org/2000/svg">'
                 . '<script>' . $payload . '</script>'
                 . '<circle r="5"/>'
                 . '</svg>';

        $this->assertGreaterThan(
            (5 * 1024 * 1024),
            strlen($svg),
            'Original payload must exceed the 5 MB cap'
        );

        $result = $this->service->upload(
            base64DataUrl: $this->dataUrl($svg)
        );

        // Sanitised result MUST be under the cap.
        $this->assertLessThan(
            ResourceService::MAX_BYTES,
            $result['size'],
            'Sanitised payload size must be under 5 MB'
        );
        $this->assertStringNotContainsString($payload, $this->persistedBytes);
        $this->assertStringContainsString('<circle', $this->persistedBytes);
    }

    public function testNearCapCleanSvgIsAccepted(): void
    {
        // A clean 4.9 MB-ish SVG whose sanitised output is roughly the
        // same size — must succeed, well under the 5 MB cap.
        $padding = str_repeat(' ', (int) (4.8 * 1024 * 1024));
        $svg     = '<svg xmlns="http://www.w3.org/2000/svg">'
                 . '<title>' . $padding . '</title>'
                 . '<circle r="5"/>'
                 . '</svg>';

        $result = $this->service->upload(
            base64DataUrl: $this->dataUrl($svg)
        );

        $this->assertLessThan(ResourceService::MAX_BYTES, $result['size']);
        $this->assertStringContainsString('<circle', $this->persistedBytes);
    }

    public function testPersistedBytesAreSanitisedNotOriginal(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
             . '<script>EVIL_MARKER_42</script>'
             . '<rect x="1" y="2" width="3" height="4"/>'
             . '</svg>';

        $this->service->upload(
            base64DataUrl: $this->dataUrl($svg)
        );

        // Persisted bytes MUST NOT be byte-equal to the original.
        $this->assertNotSame($svg, $this->persistedBytes);
        $this->assertStringNotContainsString('EVIL_MARKER_42', $this->persistedBytes);
        $this->assertStringContainsString('<rect', $this->persistedBytes);
    }
}
