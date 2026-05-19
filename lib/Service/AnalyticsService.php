<?php

/**
 * AnalyticsService
 *
 * Aggregation + reporting service for the dashboard view-analytics
 * capability (REQ-ANLT-001..010). Owns the view-event recording
 * path (called from `DashboardApiController::viewEvent`), the four
 * admin query endpoints' business logic (top, detail, summary,
 * CSV), and the period-string helper that maps `7d`/`30d`/`90d` to
 * an inclusive UTC date range.
 *
 * Privacy guarantee: this service NEVER reads or stores raw user
 * IDs in the analytics database. Unique-viewer dedup is delegated
 * to {@see UniqueViewerDedup} which uses a daily-rotating salted
 * SHA-256 hash kept exclusively in the cache (REQ-ANLT-003).
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
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\DashboardView;
use OCA\MyDash\Db\DashboardViewMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

/**
 * Reporting service for the dashboard view-analytics capability.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AnalyticsService
{
    /**
     * App-config key for the global instance-wide enable/disable
     * toggle (REQ-ANLT-005). Default `'true'`.
     *
     * @var string
     */
    public const CONFIG_KEY_ENABLED = 'analytics_enabled';

    /**
     * App-config key for the retention window in days
     * (REQ-ANLT-009). Default `'365'`. Clamped to [30, 3650].
     *
     * @var string
     */
    public const CONFIG_KEY_RETENTION_DAYS = 'analytics_retention_days';

    /**
     * Per-user preference key for the analytics opt-out flag
     * (REQ-ANLT-004). Default `'false'`.
     *
     * @var string
     */
    public const USER_PREF_KEY_OPTOUT = 'analytics_optout';

    /**
     * Default retention window in days.
     *
     * @var int
     */
    public const DEFAULT_RETENTION_DAYS = 365;

    /**
     * Minimum retention window in days (REQ-ANLT-009).
     *
     * @var int
     */
    public const MIN_RETENTION_DAYS = 30;

    /**
     * Maximum retention window in days (REQ-ANLT-009).
     *
     * @var int
     */
    public const MAX_RETENTION_DAYS = 3650;

    /**
     * Constructor.
     *
     * @param DashboardViewMapper $viewMapper      Aggregate-row mapper.
     * @param DashboardMapper     $dashboardMapper Dashboard mapper for
     *                                             name lookups.
     * @param UniqueViewerDedup   $dedup           Unique-viewer dedup
     *                                             service.
     * @param IConfig             $config          Nextcloud config
     *                                             service for
     *                                             admin/user settings.
     */
    public function __construct(
        private readonly DashboardViewMapper $viewMapper,
        private readonly DashboardMapper $dashboardMapper,
        private readonly UniqueViewerDedup $dedup,
        private readonly IConfig $config,
    ) {
    }//end __construct()

    /**
     * Report whether analytics are enabled instance-wide
     * (REQ-ANLT-005). Default `true` when the setting is absent.
     *
     * @return bool `true` when analytics is globally enabled.
     */
    public function isGloballyEnabled(): bool
    {
        $value = $this->config->getAppValue(
            'mydash',
            self::CONFIG_KEY_ENABLED,
            'true'
        );

        return ($value === 'true' || $value === '1');
    }//end isGloballyEnabled()

    /**
     * Report whether the supplied user has opted out of analytics
     * tracking (REQ-ANLT-004). Default `false`.
     *
     * @param string $userId The user identifier.
     *
     * @return bool `true` when the user has opted out.
     */
    public function isUserOptedOut(string $userId): bool
    {
        $value = $this->config->getUserValue(
            $userId,
            'mydash',
            self::USER_PREF_KEY_OPTOUT,
            'false'
        );

        return ($value === 'true' || $value === '1');
    }//end isUserOptedOut()

    /**
     * Resolve the retention window in days, clamped to the
     * `[MIN_RETENTION_DAYS, MAX_RETENTION_DAYS]` range
     * (REQ-ANLT-009).
     *
     * @return int The effective retention window.
     */
    public function getRetentionDays(): int
    {
        $value = $this->config->getAppValue(
            'mydash',
            self::CONFIG_KEY_RETENTION_DAYS,
            (string) self::DEFAULT_RETENTION_DAYS
        );

        $days = (int) $value;
        if ($days < self::MIN_RETENTION_DAYS) {
            return self::MIN_RETENTION_DAYS;
        }

        if ($days > self::MAX_RETENTION_DAYS) {
            return self::MAX_RETENTION_DAYS;
        }

        return $days;
    }//end getRetentionDays()

    /**
     * Persist a fresh retention-days value, clamped to the legal
     * bounds (REQ-ANLT-009). The clamping happens before storage so
     * the persisted value always falls in `[30, 3650]`.
     *
     * @param int $days The proposed retention window.
     *
     * @return int The clamped value that was stored.
     */
    public function setRetentionDays(int $days): int
    {
        $clamped = $days;
        if ($clamped < self::MIN_RETENTION_DAYS) {
            $clamped = self::MIN_RETENTION_DAYS;
        }

        if ($clamped > self::MAX_RETENTION_DAYS) {
            $clamped = self::MAX_RETENTION_DAYS;
        }

        $this->config->setAppValue(
            'mydash',
            self::CONFIG_KEY_RETENTION_DAYS,
            (string) $clamped
        );

        return $clamped;
    }//end setRetentionDays()

    /**
     * Persist the global enable/disable toggle.
     *
     * @param bool $enabled Whether analytics tracking is active
     *                      instance-wide.
     *
     * @return void
     */
    public function setGlobalEnabled(bool $enabled): void
    {
        $value = 'false';
        if ($enabled === true) {
            $value = 'true';
        }

        $this->config->setAppValue(
            'mydash',
            self::CONFIG_KEY_ENABLED,
            $value
        );
    }//end setGlobalEnabled()

    /**
     * Record a view event for `$dashboardUuid` by `$userId`
     * (REQ-ANLT-002, REQ-ANLT-003, REQ-ANLT-004, REQ-ANLT-005).
     *
     * Short-circuits to `false` (no-op) when:
     *   - global analytics is disabled (REQ-ANLT-005);
     *   - the user has opted out (REQ-ANLT-004).
     *
     * Always increments `viewCount` by 1 when the call proceeds;
     * `uniqueViewerCount` is incremented only when the dedup layer
     * reports the viewer as new for today.
     *
     * @param string $dashboardUuid The dashboard UUID being viewed.
     * @param string $userId        The viewing user identifier.
     *
     * @return bool `true` when an event was recorded, `false` when
     *              the call was short-circuited.
     */
    public function recordViewEvent(
        string $dashboardUuid,
        string $userId
    ): bool {
        // Existence guard surfaces a DoesNotExistException to the
        // caller so the controller can return a clean 404
        // (REQ-ANLT-002).
        $this->dashboardMapper->findByUuid(uuid: $dashboardUuid);

        if ($this->isGloballyEnabled() === false) {
            return false;
        }

        if ($this->isUserOptedOut(userId: $userId) === true) {
            return false;
        }

        $today       = UniqueViewerDedup::utcDateFor();
        $isNewViewer = $this->dedup->isNewUniqueViewer(
            userId: $userId,
            viewBucketDate: $today,
            dashboardUuid: $dashboardUuid
        );
        $uniqueDelta = 0;
        if ($isNewViewer === true) {
            $uniqueDelta = 1;
        }

        $this->viewMapper->upsertView(
            dashboardUuid: $dashboardUuid,
            viewBucket: $today,
            viewCountDelta: 1,
            uniqueCountDelta: $uniqueDelta
        );

        return true;
    }//end recordViewEvent()

    /**
     * Resolve the top-N dashboards by total view count for the
     * supplied period (REQ-ANLT-006). The dashboard `name` is
     * looked up from `DashboardMapper` per row; rows whose dashboard
     * has been deleted since the aggregate was recorded are kept
     * with `name = null` so admins still see the orphan counter.
     *
     * @param string $period The period string (`7d`, `30d`, `90d`).
     * @param int    $limit  Maximum rows.
     *
     * @return array<int, array{dashboardUuid: string, name: string|null,
     *   viewCount: int, uniqueViewerCount: int}>
     *   Top-N dashboards sorted by `viewCount` descending.
     */
    public function getTopDashboards(string $period, int $limit): array
    {
        [$startDate, $endDate] = self::periodToDateRange(period: $period);

        $rows   = $this->viewMapper->findTopDashboardsInRange(
            startDate: $startDate,
            endDate: $endDate,
            limit: $limit
        );
        $result = [];
        foreach ($rows as $row) {
            $name = null;
            try {
                $dashboard = $this->dashboardMapper->findByUuid(
                    uuid: $row['dashboardUuid']
                );
                $name      = $dashboard->getName();
            } catch (DoesNotExistException) {
                // Orphan aggregate — dashboard deleted; leave name null.
                $name = null;
            }

            $result[] = [
                'dashboardUuid'     => $row['dashboardUuid'],
                'name'              => $name,
                'viewCount'         => $row['viewCount'],
                'uniqueViewerCount' => $row['uniqueViewerCount'],
            ];
        }

        return $result;
    }//end getTopDashboards()

    /**
     * Return the daily breakdown for one dashboard (REQ-ANLT-007).
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $period        The period string.
     *
     * @return array<int, array{viewBucket: string, viewCount: int,
     *   uniqueViewerCount: int}>
     *   Daily breakdown sorted by `viewBucket` ascending. Missing
     *   days are omitted (REQ-ANLT-007 scenario).
     *
     * @throws DoesNotExistException     When the dashboard does not
     *                                   exist.
     * @throws InvalidArgumentException  When the period string is
     *                                   not one of `7d`, `30d`,
     *                                   `90d`.
     */
    public function getDashboardDetail(
        string $dashboardUuid,
        string $period
    ): array {
        // Existence guard surfaces a DoesNotExistException to the
        // controller so it can return a clean 404 (REQ-ANLT-007).
        $this->dashboardMapper->findByUuid(uuid: $dashboardUuid);

        [$startDate, $endDate] = self::periodToDateRange(period: $period);

        $entities = $this->viewMapper->findByDashboardInRange(
            dashboardUuid: $dashboardUuid,
            startDate: $startDate,
            endDate: $endDate
        );

        $result = [];
        foreach ($entities as $entity) {
            $result[] = [
                'viewBucket'        => (string) $entity->getViewBucket(),
                'viewCount'         => $entity->getViewCount(),
                'uniqueViewerCount' => $entity->getUniqueViewerCount(),
            ];
        }

        return $result;
    }//end getDashboardDetail()

    /**
     * Compute instance-wide totals + the top-5 dashboards
     * (REQ-ANLT-008).
     *
     * @param string $period The period string.
     *
     * @return array{totalViewCount: int, totalUniqueViewers: int,
     *   dashboardCount: int, period: string,
     *   top5: array<int, array{dashboardUuid: string, name: string|null,
     *     viewCount: int, uniqueViewerCount: int}>}
     *   The summary payload.
     */
    public function getInstanceSummary(string $period): array
    {
        [$startDate, $endDate] = self::periodToDateRange(period: $period);

        $totals = $this->viewMapper->findInstanceSummaryInRange(
            startDate: $startDate,
            endDate: $endDate
        );
        $top5   = $this->getTopDashboards(period: $period, limit: 5);

        return [
            'totalViewCount'     => $totals['totalViewCount'],
            'totalUniqueViewers' => $totals['totalUniqueViewers'],
            'dashboardCount'     => $totals['dashboardCount'],
            'period'             => $period,
            'top5'               => $top5,
        ];
    }//end getInstanceSummary()

    /**
     * Generate a CSV export of every aggregate row in the supplied
     * period (REQ-ANLT-010). Header row first, sorted by
     * `(dashboardUuid, viewBucket)` ascending. Dashboard names are
     * resolved lazily and cached in-method to avoid N+1 lookups.
     *
     * @param string $period The period string.
     *
     * @return string The CSV body (CRLF line endings).
     */
    public function generateCsvExport(string $period): string
    {
        [$startDate, $endDate] = self::periodToDateRange(period: $period);

        $rows = $this->viewMapper->findAllInRange(
            startDate: $startDate,
            endDate: $endDate
        );

        $nameByUuid = [];
        $output     = [];
        $output[]   = self::csvLine(
            cells: [
                'dashboardUuid',
                'dashboardName',
                'viewBucket',
                'viewCount',
                'uniqueViewerCount',
            ]
        );

        foreach ($rows as $row) {
            $uuid = (string) $row->getDashboardUuid();
            if (array_key_exists(key: $uuid, array: $nameByUuid) === false) {
                $name = '';
                try {
                    $dashboard = $this->dashboardMapper->findByUuid(
                        uuid: $uuid
                    );
                    $name      = (string) $dashboard->getName();
                } catch (DoesNotExistException) {
                    $name = '';
                }

                $nameByUuid[$uuid] = $name;
            }

            $output[] = self::csvLine(
                cells: [
                    $uuid,
                    $nameByUuid[$uuid],
                    (string) $row->getViewBucket(),
                    (string) $row->getViewCount(),
                    (string) $row->getUniqueViewerCount(),
                ]
            );
        }//end foreach

        return implode(separator: "\r\n", array: $output)."\r\n";
    }//end generateCsvExport()

    /**
     * Compute a filename suitable for the CSV export attachment
     * (REQ-ANLT-010 scenario "CSV filename includes export date").
     *
     * @return string The filename in the form
     *                `dashboard-analytics-YYYY-MM-DD.csv`.
     */
    public function csvExportFilename(): string
    {
        $today = (new DateTimeImmutable('now'))
            ->setTimezone(timezone: new DateTimeZone(timezone: 'UTC'))
            ->format(format: 'Y-m-d');

        return 'dashboard-analytics-'.$today.'.csv';
    }//end csvExportFilename()

    /**
     * Translate a period string into an inclusive UTC date range
     * `[startDate, endDate]` where `endDate` is today (UTC) and
     * `startDate` is `today - (n - 1)` so that exactly `n` days are
     * covered (REQ-ANLT-006 NOTE).
     *
     * @param string $period The period string.
     *
     * @return array{0: string, 1: string} The `[startDate,
     *                                     endDate]` pair as
     *                                     `YYYY-MM-DD`.
     *
     * @throws InvalidArgumentException When the period is unknown.
     */
    public static function periodToDateRange(string $period): array
    {
        $days = match ($period) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => null,
        };

        if ($days === null) {
            throw new InvalidArgumentException(
                message: 'Unsupported period: '.$period
            );
        }

        $today     = (new DateTimeImmutable('now'))
            ->setTimezone(timezone: new DateTimeZone(timezone: 'UTC'));
        $startDate = $today->modify(modifier: '-'.($days - 1).' days');

        return [
            $startDate->format(format: 'Y-m-d'),
            $today->format(format: 'Y-m-d'),
        ];
    }//end periodToDateRange()

    /**
     * Compute the cutoff date for the retention purge job — every
     * row with `view_bucket < cutoff` is eligible for deletion
     * (REQ-ANLT-009).
     *
     * @return string The cutoff date in `YYYY-MM-DD` format.
     */
    public function getPurgeCutoffDate(): string
    {
        $today  = (new DateTimeImmutable('now'))
            ->setTimezone(timezone: new DateTimeZone(timezone: 'UTC'));
        $cutoff = $today->modify(
            modifier: '-'.$this->getRetentionDays().' days'
        );

        return $cutoff->format(format: 'Y-m-d');
    }//end getPurgeCutoffDate()

    /**
     * Render one CSV line from the supplied cells. Quotes every
     * cell, escaping embedded quotes per RFC 4180.
     *
     * @param string[] $cells The raw cell values.
     *
     * @return string The CSV-encoded line (no trailing newline).
     */
    private static function csvLine(array $cells): string
    {
        $escaped = array_map(
            callback: static function (string $cell): string {
                return '"'.str_replace(
                    search: '"',
                    replace: '""',
                    subject: $cell
                ).'"';
            },
            array: $cells
        );

        return implode(separator: ',', array: $escaped);
    }//end csvLine()

    /**
     * Returns the entity's UUID for a dashboard. Internal helper
     * useful for tests and callers that already hold the entity.
     *
     * @param Dashboard $dashboard The dashboard entity.
     *
     * @return string The non-null UUID (returns empty string when
     *                the entity carries no UUID — never expected on
     *                a persisted row).
     */
    public static function dashboardUuidOf(Dashboard $dashboard): string
    {
        $uuid = $dashboard->getUuid();
        if ($uuid === null) {
            return '';
        }

        return $uuid;
    }//end dashboardUuidOf()

    /**
     * Internal helper exposed for the controller layer to surface a
     * canonical "view" entity payload during `viewEvent` debugging.
     *
     * @param DashboardView $view The aggregate row.
     *
     * @return array The serialised payload.
     */
    public static function viewToArray(DashboardView $view): array
    {
        return $view->jsonSerialize();
    }//end viewToArray()
}//end class
