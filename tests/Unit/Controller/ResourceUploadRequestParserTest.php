<?php

/**
 * ResourceUploadRequestParser Test
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

    public function testHappyPathReturnsBase64String(): void
    {
        $this->request->method('getHeader')->willReturn('application/json');
        $body = json_encode(['base64' => 'data:image/png;base64,AAA']);

        $result = $this->parser->extractBase64(
            request: $this->request,
            rawBody: $body
        );

        $this->assertSame('data:image/png;base64,AAA', $result);
    }

    public function testMultipartIsRejected(): void
    {
        $this->request->method('getHeader')->willReturn('multipart/form-data; boundary=---X');

        $this->expectException(UnsupportedMediaTypeException::class);
        $this->parser->extractBase64(request: $this->request, rawBody: '---X');
    }

    public function testEmptyBodyIsRejected(): void
    {
        $this->request->method('getHeader')->willReturn('application/json');

        $this->expectException(InvalidDataUrlException::class);
        $this->parser->extractBase64(request: $this->request, rawBody: '');
    }

    public function testInvalidJsonIsRejected(): void
    {
        $this->request->method('getHeader')->willReturn('application/json');

        $this->expectException(InvalidDataUrlException::class);
        $this->parser->extractBase64(
            request: $this->request,
            rawBody: '{not json'
        );
    }

    public function testNonStringBase64FieldIsRejected(): void
    {
        $this->request->method('getHeader')->willReturn('application/json');

        $this->expectException(InvalidDataUrlException::class);
        $this->parser->extractBase64(
            request: $this->request,
            rawBody: json_encode(['base64' => 12345])
        );
    }
}
