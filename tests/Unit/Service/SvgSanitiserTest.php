<?php

/**
 * SvgSanitiser Test
 *
 * Covers the 15 unit scenarios from the svg-sanitisation change
 * (REQ-RES-009..013): clean SVG round-trips, script / foreignObject /
 * on* / javascript:/data: / expression() / url(data:) all stripped,
 * safe http(s) preserved, geometry attributes preserved, non-whitelisted
 * attributes stripped, external DTD does NOT fetch, billion-laughs
 * payload bounded, unparseable bytes / empty string return null.
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

use OCA\MyDash\Service\SvgSanitiser;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

class SvgSanitiserTest extends TestCase
{
    private SvgSanitiser $sanitiser;

    protected function setUp(): void
    {
        $this->sanitiser = new SvgSanitiser();
    }

    public function testCleanSvgRoundTrips(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
             . '<rect x="0" y="0" width="10" height="10" fill="red"/>'
             . '</svg>';

        $result = $this->sanitiser->sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringContainsString('<rect', $result);
        $this->assertStringContainsString('fill="red"', $result);
    }

    public function testScriptElementRemoved(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
             . '<script>alert(1)</script>'
             . '<circle r="5"/>'
             . '</svg>';

        $result = $this->sanitiser->sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('<circle', $result);
    }

    public function testForeignObjectAndNestedIframeRemoved(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
             . '<foreignObject><iframe src="http://attacker"/></foreignObject>'
             . '<circle r="5"/>'
             . '</svg>';

        $result = $this->sanitiser->sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('foreignObject', $result);
        $this->assertStringNotContainsString('iframe', $result);
        $this->assertStringNotContainsString('attacker', $result);
        $this->assertStringContainsString('<circle', $result);
    }

    public function testEventHandlerAttributesStripped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
             . '<circle onclick="alert(1)" onload="x()" onmouseover="y()" '
             . 'onfocus="z()" r="5"/>'
             . '</svg>';

        $result = $this->sanitiser->sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('onload', $result);
        $this->assertStringNotContainsString('onmouseover', $result);
        $this->assertStringNotContainsString('onfocus', $result);
        $this->assertStringContainsString('r="5"', $result);
    }

    public function testJavascriptHrefStrippedFromXlinkHref(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" '
             . 'xmlns:xlink="http://www.w3.org/1999/xlink">'
             . '<use xlink:href="javascript:alert(1)"/>'
             . '</svg>';

        $result = $this->sanitiser->sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('javascript', $result);
        $this->assertStringContainsString('<use', $result);
    }

    public function testDataHrefStrippedFromImageHref(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
             . '<image href="data:image/svg+xml;base64,PHN2Zy8+"/>'
             . '</svg>';

        $result = $this->sanitiser->sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('data:', $result);
        $this->assertStringContainsString('<image', $result);
    }

    public function testExpressionStyleStripped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
             . '<rect style="width: expression(alert(1))" width="10" height="10"/>'
             . '</svg>';

        $result = $this->sanitiser->sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('expression', $result);
        $this->assertStringNotContainsString('style=', $result);
        $this->assertStringContainsString('width="10"', $result);
        $this->assertStringContainsString('height="10"', $result);
    }

    public function testUrlDataStyleStripped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
             . '<g style="background: url(data:text/html,foo)"><path d="M0 0"/></g>'
             . '</svg>';

        $result = $this->sanitiser->sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('style=', $result);
        $this->assertStringNotContainsString('data:', $result);
        $this->assertStringContainsString('<g', $result);
        $this->assertStringContainsString('<path', $result);
    }

    public function testSafeHttpsHrefPreserved(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
             . '<image href="https://example.com/logo.png"/>'
             . '</svg>';

        $result = $this->sanitiser->sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringContainsString('https://example.com/logo.png', $result);
    }

    public function testWhitelistedGeometryAttributesPreserved(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
             . '<rect x="10" y="20" width="100" height="50" fill="red" stroke="black"/>'
             . '</svg>';

        $result = $this->sanitiser->sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringContainsString('x="10"', $result);
        $this->assertStringContainsString('y="20"', $result);
        $this->assertStringContainsString('width="100"', $result);
        $this->assertStringContainsString('height="50"', $result);
        $this->assertStringContainsString('fill="red"', $result);
        $this->assertStringContainsString('stroke="black"', $result);
    }

    public function testNonWhitelistedAttributeStripped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'
             . '<circle data-payload="x" r="5"/>'
             . '</svg>';

        $result = $this->sanitiser->sanitize($svg);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('data-payload', $result);
        $this->assertStringContainsString('r="5"', $result);
    }

    public function testExternalDtdDoesNotFetchNetwork(): void
    {
        // DOCTYPE referencing a remote DTD that does NOT exist on the
        // network. With LIBXML_NONET the parser MUST NOT attempt the
        // fetch — the test passes if the call returns within bounded
        // time and produces either null or a sanitised string without
        // hanging on a real network request.
        $svg = '<?xml version="1.0"?>'
             . '<!DOCTYPE svg SYSTEM "http://10.255.255.1/evil.dtd">'
             . '<svg xmlns="http://www.w3.org/2000/svg"><circle r="1"/></svg>';

        $start  = microtime(true);
        $result = $this->sanitiser->sanitize($svg);
        $elapsed = (microtime(true) - $start);

        // 2 seconds is generous — a real network fetch to 10.255.255.1
        // would block for ~30 seconds before timing out.
        $this->assertLessThan(2.0, $elapsed, 'External DTD must not be fetched');
        // Result MAY be a sanitised SVG (libxml accepts the doctype
        // but does not resolve it) or null (libxml rejected the parse)
        // — either is acceptable; what matters is no network fetch.
        if ($result !== null) {
            $this->assertStringContainsString('<circle', $result);
        }
    }

    public function testBillionLaughsBoundedTime(): void
    {
        // Classic billion-laughs payload — modern libxml has internal
        // expansion limits that protect against memory exhaustion.
        $svg = '<?xml version="1.0"?>'
             . '<!DOCTYPE lolz ['
             . '<!ENTITY lol "lol">'
             . '<!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">'
             . '<!ENTITY lol3 "&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;">'
             . '<!ENTITY lol4 "&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;">'
             . '<!ENTITY lol5 "&lol4;&lol4;&lol4;&lol4;&lol4;&lol4;&lol4;&lol4;&lol4;&lol4;">'
             . ']>'
             . '<svg xmlns="http://www.w3.org/2000/svg"><title>&lol5;</title></svg>';

        $start = microtime(true);
        // We don't care about the return value — only that this returns
        // within bounded time without exhausting memory.
        $this->sanitiser->sanitize($svg);
        $elapsed = (microtime(true) - $start);

        $this->assertLessThan(5.0, $elapsed, 'Billion-laughs must be bounded');
    }

    public function testUnparseableBytesReturnNull(): void
    {
        $this->assertNull($this->sanitiser->sanitize('<not xml'));
    }

    public function testEmptyStringReturnsNull(): void
    {
        $this->assertNull($this->sanitiser->sanitize(''));
    }

    public function testNonSvgRootRejected(): void
    {
        // A well-formed XML document with a non-svg root MUST be rejected
        // — the sanitiser only accepts <svg> as the root element.
        $xml = '<html xmlns="http://www.w3.org/1999/xhtml"><body/></html>';

        $this->assertNull($this->sanitiser->sanitize($xml));
    }
}
