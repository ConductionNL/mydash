<?php

/**
 * ArchiveParser
 *
 * Reads a Confluence HTML Export ZIP archive and produces a flat list
 * of {@see ParsedPage} records along with the in-archive paths of every
 * attachment / shared image. Implements REQ-CFLI-001 (archive structure
 * parsing) and the parsing half of REQ-CFLI-002 (hierarchy from
 * directory nesting + breadcrumb override) and REQ-CFLI-003 (body
 * selector waterfall).
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
use DOMXPath;
use InvalidArgumentException;
use ZipArchive;

/**
 * Confluence HTML export ZIP reader.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *      Per-stage parsing kept in one class so the importer has a single
 *      collaborator with a flat surface (`parse()` returns everything).
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.UndefinedVariable)
 *      `preg_match` populates its by-ref `$matches` argument; PHPMD's
 *      flow analysis does not follow PHP by-reference semantics.
 */
class ArchiveParser
{

    /**
     * XPath selectors tried in order to extract the page body.
     *
     * Mirrors REQ-CFLI-003 scenario "Text-display widget captures page
     * body" — the first non-empty match wins. The fallback is regex
     * extraction of the `<body>` element, then the raw HTML.
     *
     * @var array<int, string>
     */
    private const BODY_SELECTORS = [
        "//div[@id='main-content']",
        "//div[contains(@class, 'wiki-content')]",
        "//div[contains(@class, 'page-content')]",
        "//div[@id='content']",
        '//main',
        '//article',
    ];

    /**
     * Selectors for navigation chrome that MUST be removed from the
     * extracted body before further processing (REQ-CFLI-003).
     *
     * @var array<int, string>
     */
    private const STRIP_SELECTORS = [
        "//div[@id='pagetreesearch']",
        "//div[contains(@class, 'breadcrumbs')]",
        "//div[contains(@class, 'pageSection')]",
        "//form[@name='pagetreesearchform']",
        "//form[contains(@class, 'aui')]",
        '//nav',
        "//div[contains(@class, 'page-metadata')]",
    ];

    /**
     * Parse a Confluence HTML export ZIP at the given path.
     *
     * @param string $zipPath Absolute path to the ZIP file.
     *
     * @return ParsedArchive Structured representation of the archive.
     *
     * @throws InvalidArgumentException When the ZIP is missing,
     *                                  unreadable, or has no `index.html`.
     */
    public function parse(string $zipPath): ParsedArchive
    {
        if (file_exists(filename: $zipPath) === false) {
            throw new InvalidArgumentException(
                message: 'Confluence export not found: '.$zipPath
            );
        }

        $zip = new ZipArchive();
        if ($zip->open(filename: $zipPath, flags: ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException(
                message: 'Uploaded file is not a valid ZIP archive'
            );
        }

        try {
            $entries = $this->collectEntries(zip: $zip);

            if ($entries['indexHtml'] === null) {
                throw new InvalidArgumentException(
                    message: 'index.html not found in archive'
                );
            }

            $orderMap = $this->extractPageOrderFromIndex(html: $entries['indexHtml']);
            $pages    = [];
            $warnings = [];

            foreach ($entries['htmlFiles'] as $relPath => $html) {
                $page = $this->parsePage(
                    relPath: $relPath,
                    html: $html,
                    orderMap: $orderMap
                );
                if ($page === null) {
                    $warnings[] = 'Failed to parse '.$relPath;
                    continue;
                }

                $pages[] = $page;
            }

            $this->applyDirectoryHierarchy(pages: $pages);

            return new ParsedArchive(
                pages: $pages,
                attachments: $entries['attachments'],
                images: $entries['images'],
                warnings: $warnings
            );
        } finally {
            $zip->close();
        }//end try
    }//end parse()

    /**
     * Read every relevant entry out of the ZIP into memory.
     *
     * Confluence exports of any practical size (a few MB to a few
     * hundred MB) fit comfortably; the importer is admin-triggered and
     * documented as synchronous for ≤100 pages.
     *
     * @param ZipArchive $zip The opened archive.
     *
     * @return array{
     *     indexHtml:?string,
     *     htmlFiles:array<string,string>,
     *     attachments:array<int,string>,
     *     images:array<int,string>
     * }
     */
    private function collectEntries(ZipArchive $zip): array
    {
        $indexHtml   = null;
        $htmlFiles   = [];
        $attachments = [];
        $images      = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex(index: $i);
            if ($name === '' || str_ends_with(haystack: $name, needle: '/') === true) {
                continue;
            }

            // Reject directory traversal entries up front (defence in
            // depth — the importer never writes anything to disk via the
            // entry name, but we still guard for safety).
            if (str_contains(haystack: $name, needle: '..') === true) {
                continue;
            }

            $lower = strtolower(string: $name);
            if ($lower === 'index.html') {
                $raw = $zip->getFromIndex(index: $i);
                if ($raw === false) {
                    $raw = '';
                }

                $indexHtml = $raw;
                continue;
            }

            if (str_ends_with(haystack: $lower, needle: '.html') === true) {
                $base = strtolower(string: basename(path: $name));
                if ($base === 'index.html' || $base === 'overview.html' || $base === 'toc.html') {
                    continue;
                }

                $raw = $zip->getFromIndex(index: $i);
                if ($raw === false) {
                    $raw = '';
                }

                $htmlFiles[$name] = $raw;
                continue;
            }

            if (str_starts_with(haystack: $lower, needle: 'attachments/') === true) {
                $attachments[] = $name;
                continue;
            }

            if (str_starts_with(haystack: $lower, needle: 'images/') === true) {
                $images[] = $name;
                continue;
            }
        }//end for

        return [
            'indexHtml'   => $indexHtml,
            'htmlFiles'   => $htmlFiles,
            'attachments' => $attachments,
            'images'      => $images,
        ];
    }//end collectEntries()

    /**
     * Extract the position-based ordering hint from `index.html`.
     *
     * Per REQ-CFLI-002 the index drives sibling order only — never
     * parent-child relationships. The map is `pageId => 0-based index`
     * keyed by the basename of every `<a href="…">` link in the file.
     *
     * @param string $html The index.html contents.
     *
     * @return array<string, int> The per-pageId sibling order.
     */
    private function extractPageOrderFromIndex(string $html): array
    {
        $matches = [];
        if (preg_match_all(
            pattern: '/href\s*=\s*"([^"#?]+\.html)(?:[#?][^"]*)?"/i',
            subject: $html,
            matches: $matches
        ) === false
        ) {
            return [];
        }

        $order = [];
        foreach ($matches[1] as $idx => $href) {
            $base = strtolower(string: basename(path: $href));
            $base = pathinfo(path: $base, flags: PATHINFO_FILENAME);
            if ($base === 'index' || $base === 'overview' || $base === 'toc') {
                continue;
            }

            if (isset($order[$base]) === false) {
                $order[$base] = $idx;
            }
        }

        return $order;
    }//end extractPageOrderFromIndex()

    /**
     * Parse a single Confluence HTML page into a {@see ParsedPage}.
     *
     * @param string             $relPath  The in-archive path.
     * @param string             $html     The raw HTML.
     * @param array<string, int> $orderMap pageId → sibling order.
     *
     * @return ParsedPage|null The parsed page, or NULL on hard failure.
     */
    private function parsePage(
        string $relPath,
        string $html,
        array $orderMap
    ): ?ParsedPage {
        if (trim($html) === '') {
            return null;
        }

        $pageId = pathinfo(path: $relPath, flags: PATHINFO_FILENAME);
        if ($pageId === '') {
            return null;
        }

        $title = $this->extractTitle(html: $html);
        if ($title === '') {
            $title = $pageId;
        }

        $body = $this->extractBody(html: $html);
        $breadcrumbParent = $this->extractParentFromBreadcrumb(html: $html);

        $sortOrder = (int) ($orderMap[strtolower(string: $pageId)] ?? 0);

        return new ParsedPage(
            relPath: $relPath,
            pageId: $pageId,
            title: $title,
            body: $body,
            sortOrder: $sortOrder,
            parentPageId: $breadcrumbParent,
        );
    }//end parsePage()

    /**
     * Extract the page title.
     *
     * @param string $html The page HTML.
     *
     * @return string The trimmed title, or empty string when absent.
     */
    private function extractTitle(string $html): string
    {
        if (preg_match(
            pattern: '/<title[^>]*>(.*?)<\/title>/is',
            subject: $html,
            matches: $matches
        ) === 1
        ) {
            $raw = trim((string) $matches[1]);
            // Confluence titles are often `Space : Page Name` — strip
            // the space prefix when present.
            if (str_contains(haystack: $raw, needle: ' : ') === true) {
                $raw = trim(substr(string: $raw, offset: ((int) strpos(haystack: $raw, needle: ' : ') + 3)));
            }

            return html_entity_decode(string: $raw, flags: (ENT_QUOTES | ENT_HTML5), encoding: 'UTF-8');
        }

        return '';
    }//end extractTitle()

    /**
     * Apply the body-selector waterfall + nav-strip pass.
     *
     * @param string $html The page HTML.
     *
     * @return string The cleaned body HTML (possibly empty).
     */
    private function extractBody(string $html): string
    {
        $doc      = new DOMDocument();
        $previous = libxml_use_internal_errors(use_errors: true);
        $loaded   = $doc->loadHTML(
            source: '<?xml encoding="UTF-8"?>'.$html,
            options: LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors(use_errors: $previous);

        if ($loaded === false) {
            return $this->extractBodyFallback(html: $html);
        }

        $xpath = new DOMXPath(document: $doc);

        foreach (self::BODY_SELECTORS as $selector) {
            $nodes = $xpath->query(expression: $selector);
            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $node = $nodes->item(index: 0);
            if ($node === null) {
                continue;
            }

            $this->stripUnwanted(xpath: $xpath, root: $node);

            $out = '';
            foreach ($node->childNodes as $child) {
                $out .= $doc->saveHTML(node: $child);
            }

            $trimmed = trim($out);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }//end foreach

        return $this->extractBodyFallback(html: $html);
    }//end extractBody()

    /**
     * Regex fallback for body extraction when DOMXPath finds nothing.
     *
     * @param string $html The page HTML.
     *
     * @return string The body fragment, or the raw HTML as last resort.
     */
    private function extractBodyFallback(string $html): string
    {
        if (preg_match(
            pattern: '/<body[^>]*>(.*?)<\/body>/is',
            subject: $html,
            matches: $matches
        ) === 1
        ) {
            return trim((string) $matches[1]);
        }

        return trim($html);
    }//end extractBodyFallback()

    /**
     * Remove navigation chrome from a node in place.
     *
     * @param DOMXPath $xpath The XPath.
     * @param \DOMNode $root  The root.
     *
     * @return void
     */
    private function stripUnwanted(DOMXPath $xpath, \DOMNode $root): void
    {
        foreach (self::STRIP_SELECTORS as $selector) {
            $matches = $xpath->query(expression: '.'.$selector, contextNode: $root);
            if ($matches === false) {
                continue;
            }

            // Snapshot — removeChild during iteration mutates the list.
            $remove = [];
            foreach ($matches as $node) {
                $remove[] = $node;
            }

            foreach ($remove as $node) {
                if ($node->parentNode !== null) {
                    $node->parentNode->removeChild(child: $node);
                }
            }
        }
    }//end stripUnwanted()

    /**
     * Pull the parent pageId out of the page's breadcrumb navigation.
     *
     * @param string $html The page HTML.
     *
     * @return string|null Parent pageId, or NULL when the breadcrumb is
     *                     absent / malformed / lists only the page itself.
     */
    private function extractParentFromBreadcrumb(string $html): ?string
    {
        $pattern = '/<ol[^>]*(?:class="[^"]*breadcrumbs[^"]*"|id="breadcrumbs")[^>]*>(.*?)<\/ol>/is';
        if (preg_match(pattern: $pattern, subject: $html, matches: $match) !== 1) {
            return null;
        }

        $hrefMatches = [];
        if (preg_match_all(
            pattern: '/href\s*=\s*"([^"#?]+\.html)(?:[#?][^"]*)?"/i',
            subject: (string) $match[1],
            matches: $hrefMatches
        ) === false
        ) {
            return null;
        }

        $hrefs = $hrefMatches[1];
        if (count($hrefs) === 0) {
            return null;
        }

        // The last href in the breadcrumb is the page's immediate
        // parent (the page itself is rendered as plain text, no link).
        $parentHref = (string) end($hrefs);
        $base       = pathinfo(path: $parentHref, flags: PATHINFO_FILENAME);
        if ($base === '' || $base === 'index' || $base === 'overview') {
            return null;
        }

        return $base;
    }//end extractParentFromBreadcrumb()

    /**
     * Apply directory-nesting hierarchy to pages that have no
     * breadcrumb-derived parent.
     *
     * REQ-CFLI-002 establishes directory nesting as the initial parent
     * source, with breadcrumb as the override. This pass fills in the
     * directory-derived parent for any page where breadcrumb extraction
     * returned NULL.
     *
     * @param ParsedPage[] $pages The parsed pages (mutated in place).
     *
     * @return void
     */
    private function applyDirectoryHierarchy(array $pages): void
    {
        $byPath = [];
        foreach ($pages as $page) {
            $byPath[$page->relPath] = $page;
        }

        foreach ($pages as $page) {
            if ($page->parentPageId !== null) {
                continue;
            }

            $dir = dirname(path: $page->relPath);
            if ($dir === '.' || $dir === '/' || $dir === '') {
                continue;
            }

            // Look for an `index.html` sibling in the parent dir of the
            // current dir (typical Confluence layout: `SPACE/page.html`
            // children + `SPACE.html` parent at the same level).
            $candidate = $dir.'.html';
            if (isset($byPath[$candidate]) === true) {
                $page->parentPageId = $byPath[$candidate]->pageId;
            }
        }
    }//end applyDirectoryHierarchy()
}//end class
