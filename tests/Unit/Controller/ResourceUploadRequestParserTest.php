<?php

/**
 * ResourceUploadRequestParser Test
 *
 * Covers the body-parsing seam for `POST /api/resources` (REQ-RES-001):
 * multipart bodies are rejected with HTTP 415, missing/invalid JSON
 * bodies produce `invalid_data_url`, well-formed JSON without a string
 * `base64` field also produces `invalid_data_url`, and the happy path
 * returns the raw base64 string.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\MyDash\Controller\ResourceUploadRequestParser;
use OCA\MyDash\Exception\InvalidDataUrlException;
use OCA\MyDash\Exception\UnsupportedMediaTypeException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ResourceUploadRequestParserTest extends TestCase
{
    private ResourceUploadRequestParser $parser;

    /** @var IRequest&MockObject */
    private $request;

    protected function setUp(): void
    {
        $this->parser  = new ResourceUploadRequestParser();
        $this->request = $this->createMock(IRequest::class);
    }

    private function withContentType(string $value): void
    {
        $this->request->method('getHeader')
            ->with('Content-Type')
            ->willReturn($value);
    }

    public function testHappyPathReturnsBase64String(): void
    {
        $this->withContentType('application/json');
        $result = $this->parser->extractBase64(
            request: $this->request,
            rawBody: '{"base64":"data:image/png;base64,xxx"}'
        );
        $this->assertSame('data:image/png;base64,xxx', $result);
    }

    public function testMultipartIsRejectedWith415(): void
    {
        $this->withContentType('multipart/form-data; boundary=---abc');
        $this->expectException(UnsupportedMediaTypeException::class);
        $this->parser->extractBase64(
            request: $this->request,
            rawBody: 'whatever'
        );
    }

    public function testEmptyBodyIsRejected(): void
    {
        $this->withContentType('application/json');
        $this->expectException(InvalidDataUrlException::class);
        $this->parser->extractBase64(request: $this->request, rawBody: '');
    }

    public function testMalformedJsonIsRejected(): void
    {
        $this->withContentType('application/json');
        $this->expectException(InvalidDataUrlException::class);
        $this->parser->extractBase64(
            request: $this->request,
            rawBody: '{not-json'
        );
    }

    public function testJsonWithoutBase64FieldIsRejected(): void
    {
        $this->withContentType('application/json');
        $this->expectException(InvalidDataUrlException::class);
        $this->parser->extractBase64(
            request: $this->request,
            rawBody: '{"foo":"bar"}'
        );
    }

    public function testJsonWithNonStringBase64IsRejected(): void
    {
        $this->withContentType('application/json');
        $this->expectException(InvalidDataUrlException::class);
        $this->parser->extractBase64(
            request: $this->request,
            rawBody: '{"base64":123}'
        );
    }

    public function testMissingContentTypeIsAccepted(): void
    {
        // Empty content type header should not be treated as multipart.
        $this->withContentType('');
        $result = $this->parser->extractBase64(
            request: $this->request,
            rawBody: '{"base64":"data:image/png;base64,abc"}'
        );
        $this->assertSame('data:image/png;base64,abc', $result);
    }
}
