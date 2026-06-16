<?php

/**
 * SlugGenerator
 *
 * URL-safe slug generator for dashboard names. Implements the slug
 * grammar pinned by REQ-DASH-024: lowercase ASCII alphanumerics, dash
 * (`-`) and underscore (`_`); spaces collapse to dashes; everything
 * else is stripped. The result is capped at 128 characters to match
 * the database column.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @spec openspec/changes/archive/2026-05-24-retrofit-infrastructure-helpers/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

/**
 * Slug helper used by `DashboardFactory` and the tree update path.
 */
class SlugGenerator
{
    /**
     * Maximum permitted slug length (REQ-DASH-024).
     *
     * Mirrors the `slug VARCHAR(128)` column added by
     * `Version001010Date20260502120000`.
     *
     * @var integer
     */
    public const MAX_LENGTH = 128;

    /**
     * Regex matching the legal slug grammar — lowercase alphanumerics,
     * dashes, and underscores. Empty / NULL / whitespace-only is invalid.
     *
     * @var string
     */
    public const SLUG_PATTERN = '/^[a-z0-9_-]+$/';

    /**
     * Convert an arbitrary user-supplied name into a slug.
     *
     * Steps:
     *  1. Lowercase
     *  2. Replace any run of whitespace with a single dash
     *  3. Strip every character that is not `[a-z0-9_-]`
     *  4. Collapse repeated dashes to a single dash
     *  5. Trim leading/trailing dashes
     *  6. Truncate to {@see self::MAX_LENGTH}
     *
     * Returns an empty string when the name is empty or yields no legal
     * characters; the caller decides whether to substitute a UUID
     * fallback or reject the request.
     *
     * @param string $name The dashboard name.
     *
     * @return string The slugified value (may be empty).
     *
     * @spec openspec/changes/archive/2026-05-24-retrofit-infrastructure-helpers/tasks.md#task-1
     */
    public static function slugify(string $name): string
    {
        $lower = strtolower($name);

        // Replace whitespace with a single dash before stripping so
        // multi-word names produce `q1-campaigns` not `q1campaigns`.
        $dashed = (string) preg_replace('/\s+/', '-', $lower);

        // Strip every character outside the slug grammar.
        $stripped = (string) preg_replace('/[^a-z0-9_-]+/', '', $dashed);

        // Collapse consecutive dashes (`--` → `-`).
        $collapsed = (string) preg_replace('/-+/', '-', $stripped);

        $trimmed = trim($collapsed, '-');

        if (strlen($trimmed) > self::MAX_LENGTH) {
            $trimmed = substr($trimmed, 0, self::MAX_LENGTH);
            // Re-trim in case the cut left a trailing dash.
            $trimmed = rtrim($trimmed, '-');
        }

        return $trimmed;
    }//end slugify()

    /**
     * Validate that a caller-supplied slug matches the grammar pinned
     * by REQ-DASH-024.
     *
     * @param string $slug The candidate slug.
     *
     * @return bool True when the slug is acceptable.
     *
     * @spec openspec/changes/archive/2026-05-24-retrofit-infrastructure-helpers/tasks.md#task-1
     */
    public static function isValid(string $slug): bool
    {
        if ($slug === '' || strlen($slug) > self::MAX_LENGTH) {
            return false;
        }

        return preg_match(self::SLUG_PATTERN, $slug) === 1;
    }//end isValid()
}//end class
