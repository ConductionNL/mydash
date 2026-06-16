<?php

/**
 * ParsedPage
 *
 * Plain data carrier for a single Confluence HTML export page parsed by
 * {@see ArchiveParser}. Mutable so the parser can fill in the
 * directory-derived parent in a second pass after every page has been
 * read.
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
 * One Confluence page after parsing.
 */
class ParsedPage
{
    /**
     * Constructor.
     *
     * @param string      $relPath      The in-archive path (e.g. `SPACE/page-123.html`).
     * @param string      $pageId       The page identifier (filename without extension).
     * @param string      $title        The page title.
     * @param string      $body         The cleaned body HTML (still pre-sanitisation).
     * @param int         $sortOrder    Sibling order from `index.html`, default 0.
     * @param string|null $parentPageId Parent pageId derived from breadcrumb /
     *                                  directory nesting, NULL for roots.
     */
    public function __construct(
        public readonly string $relPath,
        public readonly string $pageId,
        public readonly string $title,
        public readonly string $body,
        public readonly int $sortOrder,
        public ?string $parentPageId=null,
    ) {
    }//end __construct()
}//end class
