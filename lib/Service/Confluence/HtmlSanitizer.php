<?php

/**
 * HtmlSanitizer
 *
 * Strict allow-list HTML sanitiser used by the Confluence importer
 * (REQ-CFLI-012). Strips disallowed tags, event-handler attributes and
 * `javascript:` URLs while preserving the textual content of stripped
 * elements. Confluence-specific structured macros are dispatched to the
 * macro renderer ahead of sanitisation.
 *
 * @category  Service
 * @package   OCA\MyDash\Service\Confluence
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service\Confluence;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allow-list HTML sanitiser tuned for Confluence body content.
 */
class HtmlSanitizer
{

    /**
     * Tags preserved verbatim by {@see HtmlSanitizer::sanitize()}.
     *
     * Mirrors REQ-CFLI-012: the list is intentionally narrow so the
     * result is safe to render via Vue's `v-html` (the text-display
     * widget already runs the result through its own renderer).
     *
     * @var array<int, string>
     */
    private const ALLOWED_TAGS = [
        'p',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'a',
        'strong',
        'em',
        'b',
        'i',
        'ul',
        'ol',
        'li',
        'img',
        'table',
        'tr',
        'td',
        'th',
        'thead',
        'tbody',
        'blockquote',
        'pre',
        'code',
        'br',
        'span',
        'div',
        'details',
        'summary',
    ];

    /**
     * Tags whose content (not just the wrapper) MUST be discarded.
     *
     * Unwrapping `<script>` would leak its text payload (`alert(1)`)
     * into the output — for these tags we drop the element entirely.
     *
     * @var array<int, string>
     */
    private const STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'noscript'];

    /**
     * Per-tag attribute allow-list. Anything not listed here is dropped.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_ATTRS = [
        'a'    => ['href', 'title'],
        'img'  => ['src', 'alt', 'title', 'width', 'height'],
        'td'   => ['colspan', 'rowspan'],
        'th'   => ['colspan', 'rowspan'],
        'code' => ['class'],
        'pre'  => ['class'],
        'div'  => ['class'],
        'span' => ['class'],
    ];

    /**
     * Sanitise an HTML fragment.
     *
     * Returns a UTF-8 HTML string that contains only the allowed tags
     * and attributes. Disallowed tags are unwrapped (text content kept).
     *
     * @param string $html The raw HTML fragment.
     *
     * @return string The sanitised HTML.
     */
    public function sanitize(string $html): string
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return '';
        }

        $doc = $this->loadHtml(html: $trimmed);
        if ($doc === null) {
            return '';
        }

        $body = $doc->getElementsByTagName(qualifiedName: 'body')->item(index: 0);
        if ($body === null) {
            return '';
        }

        // Walk a snapshot of children to allow safe in-place removal.
        $children = [];
        foreach ($body->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $this->cleanNode(node: $child);
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML(node: $child);
        }

        return trim($out);
    }//end sanitize()

    /**
     * Load an HTML fragment into a DOMDocument with UTF-8 preserved.
     *
     * @param string $html The HTML fragment to load.
     *
     * @return DOMDocument|null The parsed document, or NULL on failure.
     */
    private function loadHtml(string $html): ?DOMDocument
    {
        $doc      = new DOMDocument();
        $previous = libxml_use_internal_errors(use_errors: true);
        $wrapped  = '<?xml encoding="UTF-8"?><html><body>'.$html.'</body></html>';
        $loaded   = $doc->loadHTML(
            source: $wrapped,
            options: (LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED | LIBXML_NONET)
        );
        libxml_clear_errors();
        libxml_use_internal_errors(use_errors: $previous);

        if ($loaded === false) {
            return null;
        }

        return $doc;
    }//end loadHtml()

    /**
     * Recursively clean a node, dropping disallowed tags / attributes.
     *
     * @param DOMNode $node The node to clean (modified in place).
     *
     * @return void
     */
    private function cleanNode(DOMNode $node): void
    {
        if (($node instanceof DOMElement) === false) {
            return;
        }

        $tag = strtolower(string: $node->nodeName);

        // Drop the entire element (including text content) for script /
        // style / iframe / object / embed / noscript before recursing.
        if (in_array(needle: $tag, haystack: self::STRIP_WITH_CONTENT, strict: true) === true) {
            $parent = $node->parentNode;
            if ($parent !== null) {
                $parent->removeChild(child: $node);
            }

            return;
        }

        // Snapshot children so removeChild during walk stays safe.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $this->cleanNode(node: $child);
        }

        if (in_array(needle: $tag, haystack: self::ALLOWED_TAGS, strict: true) === false) {
            $this->unwrap(element: $node);
            return;
        }

        $this->stripDisallowedAttributes(element: $node);
    }//end cleanNode()

    /**
     * Replace an element with its (already-cleaned) children.
     *
     * @param DOMElement $element The element to unwrap.
     *
     * @return void
     */
    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        // Move children out before removing the wrapper.
        while ($element->firstChild !== null) {
            $parent->insertBefore(node: $element->firstChild, child: $element);
        }

        $parent->removeChild(child: $element);
    }//end unwrap()

    /**
     * Strip disallowed and unsafe attributes from an element.
     *
     * @param DOMElement $element The element to clean.
     *
     * @return void
     */
    private function stripDisallowedAttributes(DOMElement $element): void
    {
        $tag     = strtolower(string: $element->nodeName);
        $allowed = (self::ALLOWED_ATTRS[$tag] ?? []);

        // Snapshot the attribute names — removeAttribute mutates the map.
        $names = [];
        foreach ($element->attributes as $attr) {
            $names[] = $attr->nodeName;
        }

        foreach ($names as $name) {
            $lower = strtolower(string: $name);
            if (in_array(needle: $lower, haystack: $allowed, strict: true) === false) {
                $element->removeAttribute(qualifiedName: $name);
                continue;
            }

            $value = (string) $element->getAttribute(qualifiedName: $name);
            if ($this->isUnsafeUrl(name: $lower, value: $value) === true) {
                $element->removeAttribute(qualifiedName: $name);
            }
        }
    }//end stripDisallowedAttributes()

    /**
     * Detect attributes carrying `javascript:` (or similarly unsafe) URIs.
     *
     * Only `href` and `src` are URL-bearing in the allow-list; for those
     * the value MUST NOT begin with `javascript:` (case-insensitive,
     * tolerant of leading whitespace).
     *
     * @param string $name  The attribute name (lower-cased).
     * @param string $value The attribute value.
     *
     * @return bool True when the value MUST be dropped.
     */
    private function isUnsafeUrl(string $name, string $value): bool
    {
        if ($name !== 'href' && $name !== 'src') {
            return false;
        }

        $needle = ltrim(string: $value);
        if ($needle === '') {
            return false;
        }

        return (stripos(haystack: $needle, needle: 'javascript:') === 0);
    }//end isUnsafeUrl()
}//end class
