<?php

/**
 * ParsedArchive
 *
 * Result of {@see ArchiveParser::parse()}. Bundles every parsed page
 * with the in-archive paths of every attachment and shared image so the
 * importer can rewrite `<img src="…">` references in a single pass.
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
 * Structured representation of a Confluence HTML export archive.
 */
class ParsedArchive
{
    /**
     * Constructor.
     *
     * @param ParsedPage[]      $pages       Every parsed page.
     * @param array<int,string> $attachments In-archive paths under
     *                                       `attachments/`.
     * @param array<int,string> $images      In-archive paths under
     *                                       `images/`.
     * @param array<int,string> $warnings    Non-fatal parsing warnings.
     */
    public function __construct(
        public readonly array $pages,
        public readonly array $attachments,
        public readonly array $images,
        public readonly array $warnings,
    ) {
    }//end __construct()

    /**
     * Number of parsed pages.
     *
     * @return int The page count.
     */
    /** @spec openspec/specs/confluence-html-import/spec.md */
    public function pageCount(): int
    {
        return count(value: $this->pages);
    }//end pageCount()

    /**
     * Number of attachments + shared images.
     *
     * @return int The asset count.
     */
    /** @spec openspec/specs/confluence-html-import/spec.md */
    public function attachmentCount(): int
    {
        return (count(value: $this->attachments) + count(value: $this->images));
    }//end attachmentCount()
}//end class
