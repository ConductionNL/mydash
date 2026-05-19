<?php

/**
 * Extension
 *
 * `OCP\Activity\IProvider` implementation for MyDash. Owns the canonical
 * 13-event catalogue, subject/message templates, icons, and the parser
 * that converts a raw `IEvent` into a translated, rich-formatted entry
 * for the Nextcloud Activity stream.
 *
 * Cross-capability emission contract (REQ-ACT-011):
 *
 * | Event type                         | Owning capability             |
 * |------------------------------------|-------------------------------|
 * | dashboard_created                  | dashboards                    |
 * | dashboard_updated                  | dashboards                    |
 * | dashboard_deleted                  | dashboards                    |
 * | dashboard_published                | dashboard-draft-published     |
 * | dashboard_unpublished              | dashboard-draft-published     |
 * | dashboard_scheduled                | dashboard-draft-published     |
 * | dashboard_shared                   | dashboard-sharing-followups   |
 * | dashboard_public_share_created     | dashboard-public-share        |
 * | dashboard_commented                | dashboard-comments            |
 * | dashboard_reacted                  | dashboard-reactions           |
 * | dashboard_restored                 | dashboard-versioning          |
 * | dashboard_lock_overridden          | dashboard-locking             |
 * | dashboard_role_changed             | admin-roles                   |
 *
 * Sibling capabilities MUST emit through `ActivityPublisher::publish()`
 * after the primary domain action has been persisted. They MUST NOT
 * write to `oc_activity` directly. Activity failures MUST NOT roll back
 * the owning action — `ActivityPublisher` swallows and logs exceptions.
 *
 * @category  Activity
 * @package   OCA\MyDash\Activity
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

namespace OCA\MyDash\Activity;

use OCA\MyDash\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * MyDash Activity provider.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors event catalogue size.
 */
class Extension implements IProvider
{
    /**
     * MyDash application identifier in the Activity stream.
     */
    public const APP_ID = Application::APP_ID;

    /**
     * Object type stored on every emitted IEvent.
     *
     * The activity object semantics follow NC core: `objectType` is a
     * stable string MyDash owns; `objectName` carries the dashboard
     * UUID (the IEvent::setObject signature requires the numeric
     * primary-key int as `objectId`).
     */
    public const OBJECT_TYPE = 'mydash_dashboard';

    public const EVENT_CREATED     = 'dashboard_created';
    public const EVENT_UPDATED     = 'dashboard_updated';
    public const EVENT_DELETED     = 'dashboard_deleted';
    public const EVENT_PUBLISHED   = 'dashboard_published';
    public const EVENT_UNPUBLISHED = 'dashboard_unpublished';
    public const EVENT_SCHEDULED   = 'dashboard_scheduled';
    public const EVENT_SHARED      = 'dashboard_shared';
    public const EVENT_PUBLIC_SHARE_CREATED = 'dashboard_public_share_created';
    public const EVENT_COMMENTED            = 'dashboard_commented';
    public const EVENT_REACTED         = 'dashboard_reacted';
    public const EVENT_RESTORED        = 'dashboard_restored';
    public const EVENT_LOCK_OVERRIDDEN = 'dashboard_lock_overridden';
    public const EVENT_ROLE_CHANGED    = 'dashboard_role_changed';

    /**
     * Canonical list of every MyDash event type registered with NC
     * Activity. Used for the per-type opt-out registration loop and
     * the unit-test contract.
     *
     * `dashboard_viewed` is intentionally excluded — view tracking is
     * owned by `dashboard-view-analytics` and MUST NOT be published to
     * the Activity stream (see REQ-ACT-002 and design D2).
     */
    public const ALL_EVENTS = [
        self::EVENT_CREATED,
        self::EVENT_UPDATED,
        self::EVENT_DELETED,
        self::EVENT_PUBLISHED,
        self::EVENT_UNPUBLISHED,
        self::EVENT_SCHEDULED,
        self::EVENT_SHARED,
        self::EVENT_PUBLIC_SHARE_CREATED,
        self::EVENT_COMMENTED,
        self::EVENT_REACTED,
        self::EVENT_RESTORED,
        self::EVENT_LOCK_OVERRIDDEN,
        self::EVENT_ROLE_CHANGED,
    ];

    /**
     * Constructor.
     *
     * @param IFactory      $l10nFactory  The L10N factory.
     * @param IURLGenerator $urlGenerator The URL generator.
     */
    public function __construct(
        private readonly IFactory $l10nFactory,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Parse a raw activity event into a translated, rich-formatted one.
     *
     * Returns the event with `richSubject`, `parsedSubject`, `icon`,
     * and (where applicable) message fields populated. Unknown event
     * types throw `UnknownActivityException` so the NC Activity chain
     * can pass the event to the next provider (REQ-ACT-001 scenario).
     *
     * @param string      $language      The language code.
     * @param IEvent      $event         The raw event.
     * @param IEvent|null $previousEvent A previous event for merging (unused).
     *
     * @return IEvent The parsed event.
     *
     * @throws UnknownActivityException When the event type is not handled.
     */
    public function parse(
        $language,
        IEvent $event,
        ?IEvent $previousEvent=null
    ): IEvent {
        if ($event->getApp() !== self::APP_ID) {
            throw new UnknownActivityException(
                message: 'Unknown app: '.$event->getApp()
            );
        }

        $type = $event->getType();
        if (in_array(needle: $type, haystack: self::ALL_EVENTS, strict: true) === false) {
            throw new UnknownActivityException(
                message: 'Unknown subject: '.$type
            );
        }

        $l         = $this->l10nFactory->get(app: self::APP_ID, lang: $language);
        $params    = $event->getSubjectParameters();
        $isSelf    = (bool) ($params['self'] ?? false);
        $actor     = (string) ($params['actor'] ?? $event->getAuthor());
        $dashboard = (string) ($params['dashboard'] ?? $event->getObjectName());
        $recipient = (string) ($params['recipient'] ?? '');
        $role      = (string) ($params['role'] ?? '');
        $target    = (string) ($params['target'] ?? '');

        $template = $this->resolveSubjectTemplate(
            type: $type,
            isSelf: $isSelf
        );
        $rendered = strtr(
            $l->t($template),
            [
                '{actor}'     => $actor,
                '{dashboard}' => $dashboard,
                '{recipient}' => $recipient,
                '{role}'      => $role,
                '{target}'    => $target,
            ]
        );

        $event->setRichSubject(subject: $rendered);
        $event->setParsedSubject(subject: $rendered);
        $event->setIcon(icon: $this->getIcon(eventType: $type));

        return $event;
    }//end parse()

    /**
     * Return an absolute URL to the per-type Activity icon.
     *
     * Falls back to `img/activity/mydash.svg` (the generic MyDash icon)
     * when `$eventType` is not a known constant.
     *
     * @param string $eventType The event type string.
     *
     * @return string The absolute icon URL.
     */
    public function getIcon(string $eventType): string
    {
        $known = in_array(
            needle: $eventType,
            haystack: self::ALL_EVENTS,
            strict: true
        );
        if ($known === true) {
            $file = 'activity/'.$eventType.'.svg';
        } else {
            $file = 'activity/mydash.svg';
        }

        return $this->urlGenerator->getAbsoluteURL(
            url: $this->urlGenerator->imagePath(
                appName: self::APP_ID,
                file: $file
            )
        );
    }//end getIcon()

    /**
     * Return the canonical subject-template catalogue keyed by event
     * type with `self` (first-person) and `other` (third-person)
     * variants (REQ-ACT-010).
     *
     * Templates use `{placeholder}` substitution that is rendered both
     * by `parse()` and by NC Activity's translation layer.
     *
     * @return array<string, array{self: string, other: string}>
     */
    public function getSubjectTemplates(): array
    {
        return [
            self::EVENT_CREATED              => [
                'self'  => 'You created dashboard {dashboard}',
                'other' => '{actor} created dashboard {dashboard}',
            ],
            self::EVENT_UPDATED              => [
                'self'  => 'You updated dashboard {dashboard}',
                'other' => '{actor} updated dashboard {dashboard}',
            ],
            self::EVENT_DELETED              => [
                'self'  => 'You deleted dashboard {dashboard}',
                'other' => '{actor} deleted dashboard {dashboard}',
            ],
            self::EVENT_PUBLISHED            => [
                'self'  => 'You published dashboard {dashboard}',
                'other' => '{actor} published dashboard {dashboard}',
            ],
            self::EVENT_UNPUBLISHED          => [
                'self'  => 'You unpublished dashboard {dashboard}',
                'other' => '{actor} unpublished dashboard {dashboard}',
            ],
            self::EVENT_SCHEDULED            => [
                'self'  => 'You scheduled dashboard {dashboard}',
                'other' => '{actor} scheduled dashboard {dashboard}',
            ],
            self::EVENT_SHARED               => [
                'self'  => 'You shared dashboard {dashboard} with {recipient}',
                'other' => '{actor} shared dashboard {dashboard} with {recipient}',
            ],
            self::EVENT_PUBLIC_SHARE_CREATED => [
                'self'  => 'You created a public link for dashboard {dashboard}',
                'other' => '{actor} created a public link for dashboard {dashboard}',
            ],
            self::EVENT_COMMENTED            => [
                'self'  => 'You commented on dashboard {dashboard}',
                'other' => '{actor} commented on dashboard {dashboard}',
            ],
            self::EVENT_REACTED              => [
                'self'  => 'You reacted to dashboard {dashboard}',
                'other' => '{actor} reacted to dashboard {dashboard}',
            ],
            self::EVENT_RESTORED             => [
                'self'  => 'You restored dashboard {dashboard} to an earlier version',
                'other' => '{actor} restored dashboard {dashboard} to an earlier version',
            ],
            self::EVENT_LOCK_OVERRIDDEN      => [
                'self'  => 'You overrode the lock on dashboard {dashboard}',
                'other' => '{actor} overrode the lock on dashboard {dashboard}',
            ],
            self::EVENT_ROLE_CHANGED         => [
                'self'  => 'Your role in {dashboard} was changed to {role}',
                'other' => "{actor} changed {target}'s role in {dashboard} to {role}",
            ],
        ];
    }//end getSubjectTemplates()

    /**
     * Resolve the subject template string for `$type` honoring the
     * self/other variant split.
     *
     * @param string $type   The event-type constant value.
     * @param bool   $isSelf True when the actor equals the recipient.
     *
     * @return string The template string with `{placeholder}` tokens.
     */
    private function resolveSubjectTemplate(string $type, bool $isSelf): string
    {
        $templates = $this->getSubjectTemplates();
        if ($isSelf === true) {
            $variant = 'self';
        } else {
            $variant = 'other';
        }

        return ($templates[$type][$variant] ?? '');
    }//end resolveSubjectTemplate()
}//end class
