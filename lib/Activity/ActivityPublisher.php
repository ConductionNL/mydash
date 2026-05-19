<?php

/**
 * ActivityPublisher
 *
 * Single entry point for emitting MyDash events into the Nextcloud
 * Activity stream (REQ-ACT-003). Wraps `OCP\Activity\IManager::publish()`
 * so call-sites can stay one-liner short and never construct
 * `IEvent` instances themselves.
 *
 * Audience targeting (REQ-ACT-004..006, REQ-ACT-008) is handled by
 * the dedicated `publishToOwner`, `publishToRecipients`, `publishToGroup`
 * and `publishGlobal` helpers; the generic `publish()` always emits a
 * single row to a single recipient and is the most common entry.
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

use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thin Activity emission service for MyDash.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors NC Activity surface.
 */
class ActivityPublisher
{
    /**
     * Constructor.
     *
     * @param IManager        $manager      The NC Activity manager.
     * @param IGroupManager   $groupManager The NC group manager.
     * @param IUserManager    $userManager  The NC user manager.
     * @param DebounceHelper  $debounce     The debounce guard.
     * @param LoggerInterface $logger       The logger.
     */
    public function __construct(
        private readonly IManager $manager,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly DebounceHelper $debounce,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Emit a single activity row to `$recipientUserId` for the given
     * dashboard.
     *
     * Unknown event types are silently dropped after a warning log entry
     * (REQ-ACT-002 contract). Reaction events go through the per-actor
     * debounce (REQ-ACT-007). Every NC `IManager` call is wrapped in a
     * try/catch so an Activity failure never propagates back into the
     * owning capability's HTTP handler (REQ-ACT-011 scenario).
     *
     * @param string               $type            The event-type constant value.
     * @param string               $actorUserId     The acting NC user ID.
     * @param string               $recipientUserId The recipient NC user ID.
     * @param string               $dashboardUuid   The dashboard UUID.
     * @param string               $dashboardName   The human-readable dashboard name.
     * @param string               $dashboardLink   The absolute deep-link URL.
     * @param array<string, mixed> $extraParams     Optional extra params (e.g. `recipient`, `role`, `target`, `message`).
     *
     * @return bool True when the event was successfully published; false when suppressed or dropped.
     */
    public function publish(
        string $type,
        string $actorUserId,
        string $recipientUserId,
        string $dashboardUuid,
        string $dashboardName,
        string $dashboardLink,
        array $extraParams=[]
    ): bool {
        if (in_array(needle: $type, haystack: Extension::ALL_EVENTS, strict: true) === false) {
            $this->logger->warning(
                message: 'Unknown MyDash activity type rejected',
                context: [
                    'type'      => $type,
                    'dashboard' => $dashboardUuid,
                ]
            );
            return false;
        }

        if ($type === Extension::EVENT_REACTED
            && $this->debounce->allowReaction(
                actorUserId: $actorUserId,
                dashboardUuid: $dashboardUuid
            ) === false
        ) {
            return false;
        }

        try {
            $event = $this->buildEvent(
                type: $type,
                actorUserId: $actorUserId,
                recipientUserId: $recipientUserId,
                dashboardUuid: $dashboardUuid,
                dashboardName: $dashboardName,
                dashboardLink: $dashboardLink,
                extraParams: $extraParams
            );
            $this->manager->publish(event: $event);
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'MyDash Activity publish failed',
                context: [
                    'type'      => $type,
                    'dashboard' => $dashboardUuid,
                    'recipient' => $recipientUserId,
                    'exception' => $e,
                ]
            );
            return false;
        }//end try

        return true;
    }//end publish()

    /**
     * Emit one activity row per recipient in `$recipientUserIds`, plus
     * one row to the actor (REQ-ACT-005). Recipients are de-duplicated
     * to prevent double-emission when the actor is also a recipient.
     *
     * @param string               $type             The event-type constant value.
     * @param string               $actorUserId      The acting NC user ID.
     * @param string               $dashboardUuid    The dashboard UUID.
     * @param string               $dashboardName    The dashboard name.
     * @param string               $dashboardLink    The dashboard link.
     * @param string[]             $recipientUserIds Recipient NC user IDs.
     * @param array<string, mixed> $extraParams      Optional extra params.
     *
     * @return int The number of rows successfully written.
     */
    public function publishToRecipients(
        string $type,
        string $actorUserId,
        string $dashboardUuid,
        string $dashboardName,
        string $dashboardLink,
        array $recipientUserIds,
        array $extraParams=[]
    ): int {
        $unique = array_values(
                array: array_unique(
            array: array_merge([$actorUserId], $recipientUserIds)
        )
                );

        $count = 0;
        foreach ($unique as $userId) {
            $params         = $extraParams;
            $params['self'] = ($userId === $actorUserId);
            $published      = $this->publish(
                type: $type,
                actorUserId: $actorUserId,
                recipientUserId: $userId,
                dashboardUuid: $dashboardUuid,
                dashboardName: $dashboardName,
                dashboardLink: $dashboardLink,
                extraParams: $params
            );
            if ($published === true) {
                $count++;
            }
        }

        return $count;
    }//end publishToRecipients()

    /**
     * Emit activity rows to every member of `$groupId` (REQ-ACT-006).
     *
     * Returns 0 (without raising) when the group is unknown or empty.
     * The actor is included exactly once even when they are also a
     * member of the group.
     *
     * @param string               $type          The event-type constant value.
     * @param string               $actorUserId   The acting NC user ID.
     * @param string               $groupId       The target group ID.
     * @param string               $dashboardUuid The dashboard UUID.
     * @param string               $dashboardName The dashboard name.
     * @param string               $dashboardLink The dashboard link.
     * @param array<string, mixed> $extraParams   Optional extra params.
     *
     * @return int The number of rows successfully written.
     */
    public function publishToGroup(
        string $type,
        string $actorUserId,
        string $groupId,
        string $dashboardUuid,
        string $dashboardName,
        string $dashboardLink,
        array $extraParams=[]
    ): int {
        $group = $this->groupManager->get(gid: $groupId);
        if ($group === null) {
            return 0;
        }

        $userIds = [];
        foreach ($group->getUsers() as $user) {
            $userIds[] = $user->getUID();
        }

        return $this->publishToRecipients(
            type: $type,
            actorUserId: $actorUserId,
            dashboardUuid: $dashboardUuid,
            dashboardName: $dashboardName,
            dashboardLink: $dashboardLink,
            recipientUserIds: $userIds,
            extraParams: $extraParams
        );
    }//end publishToGroup()

    /**
     * Emit activity rows to every authenticated NC user (REQ-ACT-008).
     *
     * The full fan-out is gated by
     * `DebounceHelper::allowGlobalFanout(dashboardUuid, type)` —
     * suppressed events are logged at DEBUG and produce zero rows.
     *
     * @param string               $type          The event-type constant value.
     * @param string               $actorUserId   The acting NC user ID.
     * @param string               $dashboardUuid The dashboard UUID.
     * @param string               $dashboardName The dashboard name.
     * @param string               $dashboardLink The dashboard link.
     * @param array<string, mixed> $extraParams   Optional extra params.
     *
     * @return int The number of rows successfully written (0 when debounced).
     */
    public function publishGlobal(
        string $type,
        string $actorUserId,
        string $dashboardUuid,
        string $dashboardName,
        string $dashboardLink,
        array $extraParams=[]
    ): int {
        if ($this->debounce->allowGlobalFanout(
            dashboardUuid: $dashboardUuid,
            eventType: $type
        ) === false
        ) {
            $this->logger->debug(
                message: 'MyDash global activity fan-out debounced',
                context: [
                    'type'      => $type,
                    'dashboard' => $dashboardUuid,
                ]
            );
            return 0;
        }

        $count = 0;
        $this->userManager->callForAllUsers(
            callback: function (IUser $user) use (
                $type,
                $actorUserId,
                $dashboardUuid,
                $dashboardName,
                $dashboardLink,
                $extraParams,
                &$count
            ): void {
                $params         = $extraParams;
                $params['self'] = ($user->getUID() === $actorUserId);
                $ok = $this->publish(
                    type: $type,
                    actorUserId: $actorUserId,
                    recipientUserId: $user->getUID(),
                    dashboardUuid: $dashboardUuid,
                    dashboardName: $dashboardName,
                    dashboardLink: $dashboardLink,
                    extraParams: $params
                );
                if ($ok === true) {
                    $count++;
                }
            }
        );

        return $count;
    }//end publishGlobal()

    /**
     * Build the canonical `IEvent` object for a single activity row.
     *
     * The numeric `objectId` slot in `IEvent::setObject()` requires an
     * int per the NC interface; the dashboard UUID is stored in the
     * `objectName` slot so the activity row can be deep-linked back to
     * the canonical dashboard regardless of database renumbering. The
     * subject parameters carry every field rendered by `parse()`.
     *
     * @param string               $type            The event type.
     * @param string               $actorUserId     The acting user ID.
     * @param string               $recipientUserId The recipient user ID.
     * @param string               $dashboardUuid   The dashboard UUID.
     * @param string               $dashboardName   The dashboard name.
     * @param string               $dashboardLink   The dashboard link.
     * @param array<string, mixed> $extraParams     Optional extra params.
     *
     * @return IEvent The fully populated event.
     */
    private function buildEvent(
        string $type,
        string $actorUserId,
        string $recipientUserId,
        string $dashboardUuid,
        string $dashboardName,
        string $dashboardLink,
        array $extraParams
    ): IEvent {
        $event  = $this->manager->generateEvent();
        $isSelf = ($actorUserId === $recipientUserId);

        $params = array_merge(
            [
                'self'      => $isSelf,
                'actor'     => $actorUserId,
                'dashboard' => $dashboardName,
            ],
            $extraParams
        );

        $event
            ->setApp(app: Extension::APP_ID)
            ->setType(type: $type)
            ->setAuthor(author: $actorUserId)
            ->setAffectedUser(affectedUser: $recipientUserId)
            ->setSubject(subject: $type, parameters: $params)
            ->setObject(
                objectType: Extension::OBJECT_TYPE,
                objectId: 0,
                objectName: $dashboardUuid
            )
            ->setLink(link: $dashboardLink)
            ->setTimestamp(timestamp: time());

        $message = (string) ($extraParams['message'] ?? '');
        if ($message !== '') {
            $event->setMessage(message: substr(string: $message, offset: 0, length: 200));
        }

        return $event;
    }//end buildEvent()
}//end class
