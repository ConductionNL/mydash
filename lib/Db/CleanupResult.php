<?php

/**
 * CleanupResult DTO
 *
 * Plain value object describing the outcome of a `scan` or `purge`
 * call on the orphaned-data-cleanup pipeline. REQ-CLN-001..006.
 *
 * Carries:
 *  - per-category counts (`byCategory`),
 *  - total row count across all categories,
 *  - duration in milliseconds,
 *  - whether the operation was a dry-run (purge only),
 *  - the timestamp the result was produced (used by the cache layer
 *    to surface "cached: true / cachedAt" hints in REQ-CLN-010),
 *  - the list of categories that were skipped (e.g. because the
 *    underlying feature table doesn't exist yet — REQ-CLN-001
 *    "Scan handles missing tables gracefully").
 *
 * This is NOT a Nextcloud Entity — it never touches the database. It
 * is a plain DTO so the `--dry-run` path can construct/return one
 * without any persistence side effect.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
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

namespace OCA\MyDash\Db;

use DateTime;
use JsonSerializable;

/**
 * Per-category cleanup result DTO.
 */
class CleanupResult implements JsonSerializable
{
    /**
     * Constructor.
     *
     * @param array<string, int> $byCategory Per-category orphan counts.
     * @param int                $totalRows  Sum of `byCategory` values.
     * @param int                $durationMs Wall-clock duration in
     *                                       milliseconds.
     * @param bool               $dryRun     True for dry-run purges; the
     *                                       scan path always sets this
     *                                       to `false`.
     * @param string             $scannedAt  ISO-8601 timestamp the
     *                                       result was produced
     *                                       (`Y-m-d\TH:i:s\Z` UTC).
     * @param array<int, string> $skipped    Categories that were
     *                                       skipped (missing tables,
     *                                       disabled feature).
     */
    public function __construct(
        private array $byCategory,
        private int $totalRows,
        private int $durationMs,
        private bool $dryRun,
        private string $scannedAt,
        private array $skipped=[],
    ) {
    }//end __construct()

    /**
     * Build a CleanupResult from a per-category map.
     *
     * Sums `byCategory` to derive `totalRows` and stamps `scannedAt`
     * with the current UTC time. Convenience constructor for both the
     * scan and purge paths.
     *
     * @param array<string, int> $byCategory Per-category counts.
     * @param int                $durationMs Wall-clock duration in ms.
     * @param bool               $dryRun     True for dry-run purges.
     * @param array<int, string> $skipped    Skipped category names.
     *
     * @return CleanupResult The constructed DTO.
     */
    public static function fromCounts(
        array $byCategory,
        int $durationMs,
        bool $dryRun=false,
        array $skipped=[]
    ): CleanupResult {
        $total = 0;
        foreach ($byCategory as $count) {
            $total += (int) $count;
        }

        $scannedAt = (new DateTime())->format(format: 'Y-m-d\TH:i:s\Z');

        return new CleanupResult(
            byCategory: $byCategory,
            totalRows: $total,
            durationMs: $durationMs,
            dryRun: $dryRun,
            scannedAt: $scannedAt,
            skipped: $skipped,
        );
    }//end fromCounts()

    /**
     * Get the per-category orphan count map.
     *
     * @return array<string, int> The per-category counts.
     */
    public function getByCategory(): array
    {
        return $this->byCategory;
    }//end getByCategory()

    /**
     * Get the total row count across all categories.
     *
     * @return int The total.
     */
    public function getTotalRows(): int
    {
        return $this->totalRows;
    }//end getTotalRows()

    /**
     * Get the wall-clock duration in milliseconds.
     *
     * @return int The duration.
     */
    public function getDurationMs(): int
    {
        return $this->durationMs;
    }//end getDurationMs()

    /**
     * Whether this result represents a dry-run.
     *
     * @return bool True for dry-run, false otherwise.
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }//end isDryRun()

    /**
     * Get the ISO-8601 UTC timestamp the result was produced.
     *
     * @return string The timestamp.
     */
    public function getScannedAt(): string
    {
        return $this->scannedAt;
    }//end getScannedAt()

    /**
     * Get the list of category names that were skipped (missing
     * tables, disabled feature, etc).
     *
     * @return array<int, string> The skipped category names.
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }//end getSkipped()

    /**
     * Serialize to JSON for API responses.
     *
     * @return array The serialized result.
     */
    public function jsonSerialize(): array
    {
        return [
            'byCategory' => $this->byCategory,
            'totalRows'  => $this->totalRows,
            'durationMs' => $this->durationMs,
            'dryRun'     => $this->dryRun,
            'scannedAt'  => $this->scannedAt,
            'skipped'    => $this->skipped,
        ];
    }//end jsonSerialize()
}//end class
