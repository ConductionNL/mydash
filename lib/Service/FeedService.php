<?php

/**
 * FeedService
 *
 * Renders the per-user RSS 2.0 / Atom 1.0 dashboard feed
 * (REQ-FEED-004..007). Loads the token-owner's accessible dashboards via
 * {@see DashboardService::getVisibleToUser()}, filters by the
 * publication-state and ACL rules already applied there, sorts
 * reverse-chronologically by `updatedAt`, caps at the
 * `mydash.feed_item_cap` admin-tunable bound (default 50), and emits
 * standards-compliant XML.
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

use DateTime;
use Exception;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\FeedToken;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Render an XML feed (RSS 2.0 default, Atom on Accept negotiation) of
 * the dashboards visible to a feed-token owner.
 */
class FeedService
{
    /**
     * Feed format constant — RSS 2.0 (default).
     *
     * @var string
     */
    public const FORMAT_RSS = 'rss';

    /**
     * Feed format constant — Atom 1.0.
     *
     * @var string
     */
    public const FORMAT_ATOM = 'atom';

    /**
     * Configuration key for the per-feed item cap (REQ-FEED-007).
     *
     * @var string
     */
    public const CONFIG_KEY_ITEM_CAP = 'mydash.feed_item_cap';

    /**
     * Default per-feed item cap when no admin override is set.
     *
     * @var integer
     */
    public const DEFAULT_ITEM_CAP = 50;

    /**
     * MIME type for RSS 2.0 responses.
     *
     * @var string
     */
    public const MIME_RSS = 'application/rss+xml; charset=utf-8';

    /**
     * MIME type for Atom 1.0 responses.
     *
     * @var string
     */
    public const MIME_ATOM = 'application/atom+xml; charset=utf-8';

    /**
     * Constructor.
     *
     * @param DashboardService $dashboardService Visibility resolver
     *                                           (REQ-DASH-013 / REQ-FEED-006).
     * @param IUserManager     $userManager      Display-name lookup.
     * @param IURLGenerator    $urlGenerator     Absolute-URL builder.
     * @param IAppConfig       $appConfig        App-config reader.
     * @param IFactory         $l10nFactory      L10N factory for feed labels.
     * @param LoggerInterface  $logger           Diagnostic logger.
     */
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly IUserManager $userManager,
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $appConfig,
        private readonly IFactory $l10nFactory,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Render the feed XML body for a resolved token.
     *
     * @param FeedToken $token  The active feed token (already resolved).
     * @param string    $format Either {@see self::FORMAT_RSS} or
     *                          {@see self::FORMAT_ATOM}.
     *
     * @return string The serialised feed XML.
     */
    /** @spec openspec/specs/dashboard-rss-feeds/spec.md */
    public function renderFeed(
        FeedToken $token,
        string $format=self::FORMAT_RSS
    ): string {
        $userId     = (string) $token->getUserId();
        $dashboards = $this->loadAccessibleDashboards(userId: $userId);
        $cap        = $this->resolveItemCap();
        if (count(value: $dashboards) > $cap) {
            $dashboards = array_slice(
                array: $dashboards,
                offset: 0,
                length: $cap
            );
        }

        if ($format === self::FORMAT_ATOM) {
            return $this->buildAtomFeed(
                dashboards: $dashboards,
                userId: $userId
            );
        }

        return $this->buildRssFeed(
            dashboards: $dashboards,
            userId: $userId
        );
    }//end renderFeed()

    /**
     * Resolve and load every dashboard accessible to the token-owner,
     * sorted descending by `updatedAt`. Lazy publication-state
     * materialisation, sharing visibility, and the personal/group/admin
     * filter all live in {@see DashboardService::getVisibleToUser()} —
     * the feed is a downstream consumer, not a parallel implementation.
     *
     * @param string $userId The token-owner's user ID.
     *
     * @return Dashboard[] Accessible dashboards, newest first.
     */
    /** @spec openspec/specs/dashboard-rss-feeds/spec.md */
    public function loadAccessibleDashboards(string $userId): array
    {
        $entries = $this->dashboardService->getVisibleToUser(userId: $userId);

        $dashboards = array_map(
            callback: static fn(array $entry): Dashboard => $entry['dashboard'],
            array: $entries
        );

        // Reverse-chronological by updatedAt — REQ-FEED-005.
        usort(
            array: $dashboards,
            callback: static function (Dashboard $left, Dashboard $right): int {
                $rightUpdated = (string) $right->getUpdatedAt();
                $leftUpdated  = (string) $left->getUpdatedAt();
                return strcmp(string1: $rightUpdated, string2: $leftUpdated);
            }
        );

        return $dashboards;
    }//end loadAccessibleDashboards()

    /**
     * Build an RSS 2.0 channel XML body.
     *
     * @param Dashboard[] $dashboards The capped dashboard list.
     * @param string      $userId     The token-owner's user ID.
     *
     * @return string The serialised RSS XML.
     */
    /** @spec openspec/specs/dashboard-rss-feeds/spec.md */
    public function buildRssFeed(array $dashboards, string $userId): string
    {
        $l10n         = $this->l10nFactory->get(app: Application::APP_ID);
        $ownerName    = $this->getOwnerDisplayName(userId: $userId);
        $channelTitle = sprintf(
            $l10n->t("%s's MyDash dashboards"),
            $ownerName
        );
        $channelDesc  = $l10n->t(
            'Reverse-chronological list of dashboards accessible to %s.',
            [$ownerName]
        );
        $channelLink  = $this->buildAppHomeUrl();
        $now          = (new DateTime())->format(format: 'D, d M Y H:i:s O');

        $itemsXml = '';
        foreach ($dashboards as $dashboard) {
            $itemsXml .= $this->buildRssItem(dashboard: $dashboard);
        }

        $template = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title>%s</title>
    <link>%s</link>
    <description>%s</description>
    <lastBuildDate>%s</lastBuildDate>
%s  </channel>
</rss>

XML;

        return sprintf(
            $template,
            self::xmlEscape(value: $channelTitle),
            self::xmlEscape(value: $channelLink),
            self::xmlEscape(value: $channelDesc),
            $now,
            $itemsXml
        );
    }//end buildRssFeed()

    /**
     * Build an Atom 1.0 feed XML body.
     *
     * @param Dashboard[] $dashboards The capped dashboard list.
     * @param string      $userId     The token-owner's user ID.
     *
     * @return string The serialised Atom XML.
     */
    /** @spec openspec/specs/dashboard-rss-feeds/spec.md */
    public function buildAtomFeed(array $dashboards, string $userId): string
    {
        $l10n      = $this->l10nFactory->get(app: Application::APP_ID);
        $ownerName = $this->getOwnerDisplayName(userId: $userId);
        $title     = sprintf(
            $l10n->t("%s's MyDash dashboards"),
            $ownerName
        );
        $homeUrl   = $this->buildAppHomeUrl();
        $now       = (new DateTime())->format(format: DATE_ATOM);
        $feedId    = sprintf('urn:mydash:feed:%s', rawurlencode(string: $userId));

        $entriesXml = '';
        foreach ($dashboards as $dashboard) {
            $entriesXml .= $this->buildAtomEntry(dashboard: $dashboard);
        }

        $template = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>%s</title>
  <link href="%s" />
  <id>%s</id>
  <updated>%s</updated>
  <author><name>%s</name></author>
%s</feed>

XML;

        return sprintf(
            $template,
            self::xmlEscape(value: $title),
            self::xmlEscape(value: $homeUrl),
            self::xmlEscape(value: $feedId),
            $now,
            self::xmlEscape(value: $ownerName),
            $entriesXml
        );
    }//end buildAtomFeed()

    /**
     * Resolve the owner's friendly display name. Falls back to the user
     * ID when the user record cannot be loaded — matches the
     * REQ-FEED-005 "author" element guarantee.
     *
     * @param string $userId The user ID.
     *
     * @return string The display name (or the user ID when unknown).
     */
    /** @spec openspec/specs/dashboard-rss-feeds/spec.md */
    public function getOwnerDisplayName(string $userId): string
    {
        $user = $this->userManager->get(uid: $userId);
        if ($user === null) {
            return $userId;
        }

        $name = trim(string: $user->getDisplayName());
        if ($name === '') {
            return $userId;
        }

        return $name;
    }//end getOwnerDisplayName()

    /**
     * XML-escape a string for safe interpolation into element bodies
     * and double-quoted attribute values (REQ-FEED-005 scenario
     * "Dashboard description with special XML characters").
     *
     * @param string|null $value The raw value.
     *
     * @return string The escaped value, or empty string when null.
     */
    /** @spec openspec/specs/dashboard-rss-feeds/spec.md */
    public static function xmlEscape(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars(
            string: $value,
            flags: (ENT_QUOTES | ENT_XML1),
            encoding: 'UTF-8'
        );
    }//end xmlEscape()

    /**
     * Resolve the admin-tunable feed-item cap from app config.
     *
     * Returns {@see self::DEFAULT_ITEM_CAP} when the config value is
     * missing or not a positive integer (REQ-FEED-007).
     *
     * @return int The cap (>= 1).
     */
    private function resolveItemCap(): int
    {
        $cap = $this->appConfig->getValueInt(
            Application::APP_ID,
            self::CONFIG_KEY_ITEM_CAP,
            self::DEFAULT_ITEM_CAP
        );

        if ($cap < 1) {
            return self::DEFAULT_ITEM_CAP;
        }

        return $cap;
    }//end resolveItemCap()

    /**
     * Build the absolute URL for the workspace home page.
     *
     * @return string The absolute URL (or `'/'` on resolver failure).
     */
    private function buildAppHomeUrl(): string
    {
        try {
            return $this->urlGenerator->linkToRouteAbsolute(
                routeName: Application::APP_ID.'.page.index'
            );
        } catch (Throwable $exception) {
            $this->logger->debug(
                message: 'Feed could not resolve app home URL',
                context: [
                    'app'       => 'mydash',
                    'exception' => $exception->getMessage(),
                ]
            );
            return '/';
        }
    }//end buildAppHomeUrl()

    /**
     * Build the absolute deep-link to a single dashboard.
     *
     * Falls back to the workspace home with a `#/dashboard/<uuid>`
     * fragment so RSS readers always have a clickable target even when
     * the route generator does not know the SPA fragment routes.
     *
     * @param Dashboard $dashboard The dashboard.
     *
     * @return string The absolute deep-link URL.
     */
    private function buildDashboardLink(Dashboard $dashboard): string
    {
        $home = $this->buildAppHomeUrl();
        $uuid = (string) $dashboard->getUuid();
        if ($uuid === '') {
            return $home;
        }

        return sprintf('%s#/dashboard/%s', $home, rawurlencode(string: $uuid));
    }//end buildDashboardLink()

    /**
     * Render a single RSS `<item>` element for a dashboard.
     *
     * @param Dashboard $dashboard The dashboard.
     *
     * @return string The serialised item XML.
     */
    private function buildRssItem(Dashboard $dashboard): string
    {
        $title       = (string) $dashboard->getName();
        $link        = $this->buildDashboardLink(dashboard: $dashboard);
        $description = (string) $dashboard->getDescription();
        $pubDate     = $this->formatRfc2822(timestamp: $dashboard->getUpdatedAt());
        $guid        = (string) $dashboard->getUuid();
        $author      = $this->getOwnerDisplayName(
            userId: (string) $dashboard->getUserId()
        );

        $template = <<<XML
    <item>
      <title>%s</title>
      <link>%s</link>
      <description>%s</description>
      <pubDate>%s</pubDate>
      <guid isPermaLink="false">%s</guid>
      <author>%s</author>
    </item>

XML;

        return sprintf(
            $template,
            self::xmlEscape(value: $title),
            self::xmlEscape(value: $link),
            self::xmlEscape(value: $description),
            self::xmlEscape(value: $pubDate),
            self::xmlEscape(value: $guid),
            self::xmlEscape(value: $author)
        );
    }//end buildRssItem()

    /**
     * Render a single Atom `<entry>` element for a dashboard.
     *
     * @param Dashboard $dashboard The dashboard.
     *
     * @return string The serialised entry XML.
     */
    private function buildAtomEntry(Dashboard $dashboard): string
    {
        $title       = (string) $dashboard->getName();
        $link        = $this->buildDashboardLink(dashboard: $dashboard);
        $description = (string) $dashboard->getDescription();
        $updated     = $this->formatAtomDate(timestamp: $dashboard->getUpdatedAt());
        $uuid        = (string) $dashboard->getUuid();
        $entryId     = sprintf('urn:mydash:dashboard:%s', rawurlencode(string: $uuid));

        $template = <<<XML
  <entry>
    <title>%s</title>
    <link href="%s" />
    <id>%s</id>
    <updated>%s</updated>
    <summary>%s</summary>
  </entry>

XML;

        return sprintf(
            $template,
            self::xmlEscape(value: $title),
            self::xmlEscape(value: $link),
            self::xmlEscape(value: $entryId),
            $updated,
            self::xmlEscape(value: $description)
        );
    }//end buildAtomEntry()

    /**
     * Format a `Y-m-d H:i:s` timestamp string as RFC 2822 (RSS pubDate).
     *
     * Falls back to the current time on parse failure so a malformed
     * timestamp on a single dashboard never breaks the whole feed.
     *
     * @param string|null $timestamp The raw timestamp.
     *
     * @return string The RFC 2822 representation.
     */
    private function formatRfc2822(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return (new DateTime())->format(format: 'D, d M Y H:i:s O');
        }

        try {
            return (new DateTime($timestamp))
                ->format(format: 'D, d M Y H:i:s O');
        } catch (Exception) {
            return (new DateTime())->format(format: 'D, d M Y H:i:s O');
        }
    }//end formatRfc2822()

    /**
     * Format a `Y-m-d H:i:s` timestamp string as Atom date-time.
     *
     * Falls back to the current time on parse failure (see
     * {@see self::formatRfc2822()} for the rationale).
     *
     * @param string|null $timestamp The raw timestamp.
     *
     * @return string The Atom date-time representation.
     */
    private function formatAtomDate(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return (new DateTime())->format(format: DATE_ATOM);
        }

        try {
            return (new DateTime($timestamp))->format(format: DATE_ATOM);
        } catch (Exception) {
            return (new DateTime())->format(format: DATE_ATOM);
        }
    }//end formatAtomDate()
}//end class
