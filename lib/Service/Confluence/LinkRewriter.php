<?php

/**
 * LinkRewriter
 *
 * Post-import pass that rewrites internal Confluence `<a href="…">`
 * page links to MyDash dashboard deep links. External URLs (any
 * scheme-prefixed `http(s):`, `mailto:`, etc.) are left untouched.
 * Implements REQ-CFLI-004.
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

/**
 * Rewrites internal Confluence links to MyDash deep links.
 */
class LinkRewriter
{
    /**
     * Rewrite Confluence page links inside an HTML fragment.
     *
     * @param string                $html         The HTML fragment.
     * @param array<string, string> $pageIdToUuid Map of pageId → dashboard UUID.
     *
     * @return array{html:string, warnings:array<int,string>}
     *      The rewritten fragment plus any warnings (links whose target
     *      pageId did not appear in the import).
     */
    /** @spec openspec/specs/confluence-html-import/spec.md */
    public function rewrite(string $html, array $pageIdToUuid): array
    {
        if ($html === '') {
            return ['html' => '', 'warnings' => []];
        }

        $warnings = [];

        $callback = function (array $match) use ($pageIdToUuid, &$warnings): string {
            $href = (string) ($match[2] ?? '');
            $rest = (string) ($match[3] ?? '');

            if ($this->isExternal(href: $href) === true) {
                return $match[0];
            }

            $base    = pathinfo(path: $href, flags: PATHINFO_FILENAME);
            $baseKey = strtolower(string: $base);

            if ($baseKey === '' || isset($pageIdToUuid[$baseKey]) === false) {
                if ($baseKey !== '') {
                    $warnings[] = 'Link to unknown Confluence page: '.$href;
                }

                return $match[0];
            }

            $newHref = '/apps/mydash/dashboard/'.$pageIdToUuid[$baseKey];
            return '<a href="'.$newHref.'"'.$rest.'>';
        };

        $pattern = '/<a\b([^>]*?)href="([^"]+)"([^>]*)>/i';
        $result  = preg_replace_callback(
            pattern: $pattern,
            callback: $callback,
            subject: $html
        );

        return [
            'html'     => ($result ?? $html),
            'warnings' => $warnings,
        ];
    }//end rewrite()

    /**
     * Determine whether an `href` points outside the Confluence export.
     *
     * @param string $href The href value.
     *
     * @return bool True when the link MUST be preserved verbatim.
     */
    private function isExternal(string $href): bool
    {
        $needle = ltrim(string: $href);
        if ($needle === '') {
            return true;
        }

        if (str_starts_with(haystack: $needle, needle: '#') === true) {
            return true;
        }

        if (str_starts_with(haystack: $needle, needle: '/') === true) {
            return true;
        }

        // Any scheme prefix (`http:`, `https:`, `mailto:`, `tel:`, …) is external.
        if (preg_match(pattern: '/^[a-z][a-z0-9+.\-]*:/i', subject: $needle) === 1) {
            return true;
        }

        // Confluence page links end in `.html`. Anything else falls
        // through unchanged.
        return (str_ends_with(haystack: strtolower(string: $needle), needle: '.html') === false);
    }//end isExternal()
}//end class
