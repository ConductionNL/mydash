<?php

/**
 * Notifier
 *
 * INotifier implementation for MyDash. Renders `dashboard_shared` and
 * `dashboard_ownership_transferred` notifications in the Nextcloud bell,
 * activity stream, and email digest. REQ-SHARE-011.
 *
 * @category  Notification
 * @package   OCA\MyDash\Notification
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

namespace OCA\MyDash\Notification;

use InvalidArgumentException;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\DashboardMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * MyDash notification renderer.
 *
 * Handles three subjects:
 * - `dashboard_shared` — published when a dashboard is shared with a user or
 *   when a share's permission_level is upgraded (REQ-SHARE-008).
 * - `dashboard_ownership_transferred` — published when a UserDeletedEvent
 *   causes ownership of a dashboard to be transferred to a new owner
 *   (REQ-SHARE-013).
 * - `mentioned_in_comment` — published when a user is `@-mentioned` inside
 *   a dashboard comment (REQ-CMNT-006). For this subject the
 *   `INotification::getObjectId()` is the dashboard UUID directly (not
 *   the DB id), so the URL builder takes a different branch.
 */
class Notifier implements INotifier
{
    /**
     * Constructor
     *
     * @param IFactory        $l10nFactory     The L10N factory.
     * @param IURLGenerator   $urlGenerator    The URL generator.
     * @param DashboardMapper $dashboardMapper The dashboard mapper.
     */
    public function __construct(
        private readonly IFactory $l10nFactory,
        private readonly IURLGenerator $urlGenerator,
        private readonly DashboardMapper $dashboardMapper,
    ) {
    }//end __construct()

    /**
     * Return the notifier app ID.
     *
     * @return string The app ID.
     */
    public function getID(): string
    {
        return Application::APP_ID;
    }//end getID()

    /**
     * Return a human-readable notifier name.
     *
     * @return string The notifier name.
     */
    public function getName(): string
    {
        return $this->l10nFactory->get(app: Application::APP_ID)->t('MyDash');
    }//end getName()

    /**
     * Prepare and render an INotification for display.
     *
     * Handles `dashboard_shared` and `dashboard_ownership_transferred`.
     * Throws `UnknownNotificationException` for any other subject so that
     * the Nextcloud notification chain can pass it to the next notifier.
     *
     * @param INotification $notification The raw notification.
     * @param string        $languageCode The language code for the recipient.
     *
     * @return INotification The prepared notification.
     *
     * @throws UnknownNotificationException When the subject is not handled.
     */
    public function prepare(
        INotification $notification,
        string $languageCode
    ): INotification {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new UnknownNotificationException(
                message: 'Unknown app: '.$notification->getApp()
            );
        }

        $l       = $this->l10nFactory->get(
            app: Application::APP_ID,
            lang: $languageCode
        );
        $subject = $notification->getSubject();

        // The mention subject ships the dashboard UUID directly as the
        // notification's objectId; the share / transfer subjects ship the
        // dashboard DB id (legacy contract). REQ-CMNT-006.
        if ($subject === 'mentioned_in_comment') {
            $url = $this->buildDashboardUrlFromUuid(
                uuid: $notification->getObjectId()
            );

            return $this->prepareMentionedInComment(
                notification: $notification,
                l: $l,
                url: $url
            );
        }

        $url = $this->buildDashboardUrl(
            objectId: $notification->getObjectId()
        );

        if ($subject === 'dashboard_shared') {
            return $this->prepareDashboardShared(
                notification: $notification,
                l: $l,
                url: $url
            );
        }

        if ($subject === 'dashboard_ownership_transferred') {
            return $this->prepareOwnershipTransferred(
                notification: $notification,
                l: $l,
                url: $url
            );
        }

        throw new UnknownNotificationException(
            message: 'Unknown subject: '.$subject
        );
    }//end prepare()

    /**
     * Prepare a `mentioned_in_comment` notification (REQ-CMNT-006).
     *
     * Subject parameters: [authorUserId, dashboardUuid].
     *
     * @param INotification $notification The notification.
     * @param IL10N         $l            The L10N instance.
     * @param string        $url          The deep-link URL.
     *
     * @return INotification The prepared notification.
     */
    private function prepareMentionedInComment(
        INotification $notification,
        IL10N $l,
        string $url
    ): INotification {
        $params = $notification->getSubjectParameters();
        $author = (string) ($params[0] ?? '');

        $richSubject = $l->t(
            '%1$s mentioned you in a dashboard comment',
            [$author]
        );
        $notification->setRichSubject(
            subject: $richSubject,
            parameters: []
        );
        $notification->setParsedSubject(subject: $richSubject);

        $message = $l->t('Open the dashboard to read the comment');
        $notification->setRichMessage(
            message: $message,
            parameters: []
        );
        $notification->setParsedMessage(message: $message);

        $notification->setLink(link: $url);

        return $notification;
    }//end prepareMentionedInComment()

    /**
     * Prepare a `dashboard_shared` notification.
     *
     * Subject parameters: [sharerUserId, dashboardName, permissionLevel].
     *
     * @param INotification $notification The notification.
     * @param IL10N         $l            The L10N instance.
     * @param string        $url          The deep-link URL.
     *
     * @return INotification The prepared notification.
     */
    private function prepareDashboardShared(
        INotification $notification,
        IL10N $l,
        string $url
    ): INotification {
        $params = $notification->getSubjectParameters();
        $sharer = $params[0] ?? '';
        $name   = $params[1] ?? '';
        $level  = $params[2] ?? '';

        $richSubject = $l->t(
            '%1$s shared **%2$s** with you',
            [$sharer, $name]
        );
        $notification->setRichSubject(
            subject: $richSubject,
            parameters: []
        );
        $notification->setParsedSubject(
            subject: $l->t(
                '%1$s shared %2$s with you',
                [$sharer, $name]
            )
        );

        $levelLabel = $this->permissionLabel(l: $l, level: $level);
        $notification->setRichMessage(
            message: $levelLabel,
            parameters: []
        );
        $notification->setParsedMessage(message: $levelLabel);

        $notification->setLink(link: $url);

        return $notification;
    }//end prepareDashboardShared()

    /**
     * Prepare a `dashboard_ownership_transferred` notification.
     *
     * Subject parameters: [dashboardName].
     *
     * @param INotification $notification The notification.
     * @param IL10N         $l            The L10N instance.
     * @param string        $url          The deep-link URL.
     *
     * @return INotification The prepared notification.
     */
    private function prepareOwnershipTransferred(
        INotification $notification,
        IL10N $l,
        string $url
    ): INotification {
        $params = $notification->getSubjectParameters();
        $name   = $params[0] ?? '';

        $richSubject = $l->t('**%1$s** is now yours', [$name]);
        $notification->setRichSubject(
            subject: $richSubject,
            parameters: []
        );
        $notification->setParsedSubject(
            subject: $l->t('%1$s is now yours', [$name])
        );

        $message = $l->t(
            'Ownership transferred after the previous owner was removed'
        );
        $notification->setRichMessage(
            message: $message,
            parameters: []
        );
        $notification->setParsedMessage(message: $message);

        $notification->setLink(link: $url);

        return $notification;
    }//end prepareOwnershipTransferred()

    /**
     * Build the deep-link URL for a dashboard.
     *
     * Falls back to the base index route when the objectId cannot be
     * resolved to a UUID (e.g. when the dashboard was deleted between
     * notification creation and rendering).
     *
     * @param string $objectId The dashboard DB ID (as string per INotification).
     *
     * @return string The URL.
     */
    private function buildDashboardUrl(string $objectId): string
    {
        $base = $this->urlGenerator->linkToRouteAbsolute(
            routeName: 'mydash.page.index'
        );

        if ($objectId === '') {
            return $base;
        }

        try {
            $dashboard = $this->dashboardMapper->find(id: (int) $objectId);
            $uuid      = $dashboard->getUuid();
            if ($uuid !== null && $uuid !== '') {
                return $base.'?dashboard='.urlencode(string: $uuid);
            }
        } catch (DoesNotExistException) {
            // Dashboard deleted — return base URL.
        }

        return $base;
    }//end buildDashboardUrl()

    /**
     * Build a deep-link URL when the objectId is already the dashboard
     * UUID — used by the comment-mention notification (REQ-CMNT-006).
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return string The URL.
     */
    private function buildDashboardUrlFromUuid(string $uuid): string
    {
        $base = $this->urlGenerator->linkToRouteAbsolute(
            routeName: 'mydash.page.index'
        );

        if ($uuid === '') {
            return $base;
        }

        return $base.'?dashboard='.urlencode(string: $uuid);
    }//end buildDashboardUrlFromUuid()

    /**
     * Return the human-readable label for a permission level.
     *
     * @param IL10N  $l     The L10N instance.
     * @param string $level The permission level identifier.
     *
     * @return string The translated label.
     */
    private function permissionLabel(
        IL10N $l,
        string $level
    ): string {
        return match ($level) {
            'full'      => $l->t('Full access'),
            'add_only'  => $l->t('Add-only access'),
            'view_only' => $l->t('View-only access'),
            default     => $l->t('Shared access'),
        };
    }//end permissionLabel()
}//end class
