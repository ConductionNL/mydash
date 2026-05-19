<?php

/**
 * CommentService
 *
 * Business-logic layer for the dashboard-comments capability. Wraps the
 * Nextcloud `ICommentsManager` infrastructure with the MyDash-specific
 * rules: object-type binding (`mydash_dashboard`), one-level-deep
 * threading, author-or-admin mutation guard, soft-delete cascade,
 * mention parsing, and toggle precedence (REQ-CMNT-001..009).
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
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Exception\CommentForbiddenException;
use OCA\MyDash\Exception\CommentNotFoundException;
use OCA\MyDash\Exception\InvalidCommentException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException as CommentManagerNotFoundException;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;

/**
 * Comment CRUD service backed by `ICommentsManager` (REQ-CMNT-001..009).
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   Validation, CRUD,
 *                                                serialisation and
 *                                                mention parsing belong
 *                                                on a single cohesive
 *                                                comment service.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The comment service
 *                                                  legitimately spans
 *                                                  the comments manager,
 *                                                  user manager,
 *                                                  notification manager,
 *                                                  admin settings, and
 *                                                  URL generator — each
 *                                                  represents a different
 *                                                  Nextcloud subsystem
 *                                                  the comment workflow
 *                                                  must integrate with.
 */
class CommentService
{
    /**
     * Object type string used to scope comments to MyDash dashboards
     * (REQ-CMNT-001 / D2 in design.md).
     *
     * @var string
     */
    public const OBJECT_TYPE = 'mydash_dashboard';

    /**
     * Notification subject for the @mention event (REQ-CMNT-006).
     *
     * @var string
     */
    public const NOTIFICATION_SUBJECT = 'mentioned_in_comment';

    /**
     * Regex used to extract `@username` mentions from message text.
     *
     * Matches a leading `@` followed by Nextcloud-friendly user-id
     * characters (letters, digits, `_`, `.`, `-`). The username is
     * deduplicated and case-normalised by the caller.
     *
     * @var string
     */
    private const MENTION_REGEX = '/@([a-zA-Z0-9_.-]+)/';

    /**
     * Constructor
     *
     * @param ICommentsManager     $commentsManager     Nextcloud comments backend.
     * @param IUserManager         $userManager         Nextcloud user manager.
     * @param INotificationManager $notificationManager Nextcloud notification manager.
     * @param IGroupManager        $groupManager        Used only for the
     *                                                  `isAdmin` check
     *                                                  on edit/delete
     *                                                  authorisation.
     * @param IURLGenerator        $urlGenerator        URL generator for the
     *                                                  deep-link payload
     *                                                  sent with mention
     *                                                  notifications.
     * @param AdminSettingMapper   $settingMapper       Reads the global
     *                                                  `comments_enabled_default`
     *                                                  admin setting.
     */
    public function __construct(
        private readonly ICommentsManager $commentsManager,
        private readonly IUserManager $userManager,
        private readonly INotificationManager $notificationManager,
        private readonly IGroupManager $groupManager,
        private readonly IURLGenerator $urlGenerator,
        private readonly AdminSettingMapper $settingMapper,
    ) {
    }//end __construct()

    /**
     * Read the global `mydash.comments_enabled_default` setting
     * (REQ-CMNT-008).
     *
     * Defaults to `true` when the setting is missing — fresh installs
     * have comments enabled by design.
     *
     * @return bool True when comments are globally enabled.
     */
    public function isCommentsEnabledGlobally(): bool
    {
        try {
            $setting = $this->settingMapper->findByKey(
                key: AdminSetting::KEY_COMMENTS_ENABLED_DEFAULT
            );
        } catch (DoesNotExistException) {
            return true;
        }

        $decoded = $setting->getValueDecoded();
        if (is_bool($decoded) === true) {
            return $decoded;
        }

        // Tolerate string `"true"` / `"1"` for hand-edited rows.
        if (is_string($decoded) === true) {
            return in_array(
                needle: strtolower($decoded),
                haystack: ['true', '1', 'yes', 'on'],
                strict: true
            );
        }

        if (is_int($decoded) === true) {
            return $decoded !== 0;
        }

        return true;
    }//end isCommentsEnabledGlobally()

    /**
     * Whether comments are effectively enabled on a dashboard
     * (REQ-CMNT-007, REQ-CMNT-008).
     *
     * @param Dashboard $dashboard The dashboard.
     *
     * @return bool True when comments are effectively enabled.
     */
    public function isEnabledFor(Dashboard $dashboard): bool
    {
        return $dashboard->isCommentsEffectivelyEnabled(
            globalDefault: $this->isCommentsEnabledGlobally()
        );
    }//end isEnabledFor()

    /**
     * Return the threaded comment list for a dashboard (REQ-CMNT-001).
     *
     * Top-level comments are ordered newest first; replies are grouped
     * underneath their parent in chronological order. Each entry is the
     * serialised array shape returned by {@see self::serialiseComment()}.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return array<int, array<string, mixed>> Top-level comments with
     *                                          a nested `replies` array.
     */
    public function getCommentsForDashboard(string $dashboardUuid): array
    {
        $raw = $this->commentsManager->getForObject(
            objectType: self::OBJECT_TYPE,
            objectId: $dashboardUuid,
            limit: 0,
            offset: 0
        );

        $topLevel = [];
        $replies  = [];

        foreach ($raw as $comment) {
            $parentId = (int) $comment->getParentId();
            if ($parentId === 0) {
                $topLevel[] = $comment;
                continue;
            }

            if (isset($replies[$parentId]) === false) {
                $replies[$parentId] = [];
            }

            $replies[$parentId][] = $comment;
        }

        // Newest first for top-level (REQ-CMNT-001 ordering).
        usort(
            $topLevel,
            static function (IComment $left, IComment $right): int {
                return $right->getCreationDateTime()->getTimestamp() <=> $left->getCreationDateTime()->getTimestamp();
            }
        );

        $result = [];
        foreach ($topLevel as $top) {
            $serialised   = $this->serialiseComment(comment: $top);
            $childPayload = [];
            $children     = $replies[(int) $top->getId()] ?? [];

            // Replies stay chronological so the conversation reads top-down.
            usort(
                $children,
                static function (IComment $left, IComment $right): int {
                    return $left->getCreationDateTime()->getTimestamp() <=> $right->getCreationDateTime()->getTimestamp();
                }
            );

            foreach ($children as $child) {
                $childPayload[] = $this->serialiseComment(comment: $child);
            }

            $serialised['replies'] = $childPayload;
            $result[] = $serialised;
        }//end foreach

        return $result;
    }//end getCommentsForDashboard()

    /**
     * Create a new top-level comment or one-level-deep reply
     * (REQ-CMNT-002, REQ-CMNT-003).
     *
     * @param string   $dashboardUuid The dashboard UUID.
     * @param string   $userId        The author user ID.
     * @param string   $message       The non-empty message text.
     * @param int|null $parentId      Optional parent comment ID for replies.
     *
     * @return IComment The persisted comment.
     *
     * @throws InvalidCommentException  On empty message or nested-reply violation.
     * @throws CommentNotFoundException When `$parentId` does not resolve.
     */
    public function createComment(
        string $dashboardUuid,
        string $userId,
        string $message,
        ?int $parentId=null
    ): IComment {
        $trimmed = trim(string: $message);
        if ($trimmed === '') {
            throw new InvalidCommentException(
                message: 'Comment message must not be empty',
                errorCode: 'comment_empty_message'
            );
        }

        $resolvedParentId = 0;
        if ($parentId !== null && $parentId > 0) {
            $parent = $this->fetchComment(commentId: $parentId);

            // Ensure parent belongs to the same dashboard so callers
            // cannot cross-thread between dashboards.
            if ($parent->getObjectType() !== self::OBJECT_TYPE
                || $parent->getObjectId() !== $dashboardUuid
            ) {
                throw new CommentNotFoundException(
                    message: 'Parent comment not found'
                );
            }

            // One-level-deep enforcement (REQ-CMNT-003 / D4).
            if ((int) $parent->getParentId() !== 0) {
                throw new InvalidCommentException(
                    message: 'Comments can only be replied to once',
                    errorCode: 'comment_nested_reply'
                );
            }

            $resolvedParentId = (int) $parent->getId();
        }//end if

        $comment = $this->commentsManager->create(
            actorType: 'users',
            actorId: $userId,
            objectType: self::OBJECT_TYPE,
            objectId: $dashboardUuid
        );
        $comment->setMessage(message: $trimmed);
        $comment->setVerb(verb: 'comment');
        if ($resolvedParentId > 0) {
            $comment->setParentId(parentId: (string) $resolvedParentId);
        }

        $this->commentsManager->save(comment: $comment);

        $this->parseAndResolveMentions(
            message: $trimmed,
            dashboardUuid: $dashboardUuid,
            authorUserId: $userId
        );

        return $comment;
    }//end createComment()

    /**
     * Update the message of a comment (REQ-CMNT-004).
     *
     * Only the original author or a Nextcloud admin may edit. Sets the
     * `wasEdited` marker via the comment's `latestChildDateTime` semantics
     * is not used here — instead `ICommentsManager::save()` updates the
     * `latest_child_message` and the comment's serialisation surface
     * `wasEdited = true` whenever the persisted entity carries an
     * updated-at timestamp.
     *
     * @param int    $commentId     The comment ID.
     * @param string $newMessage    The new message text.
     * @param string $currentUserId The acting user's ID.
     *
     * @return IComment The updated comment.
     *
     * @throws CommentNotFoundException  When the comment does not exist.
     * @throws CommentForbiddenException When the user is neither author
     *                                   nor admin.
     * @throws InvalidCommentException   When the message is empty.
     */
    public function updateComment(
        int $commentId,
        string $newMessage,
        string $currentUserId
    ): IComment {
        $trimmed = trim(string: $newMessage);
        if ($trimmed === '') {
            throw new InvalidCommentException(
                message: 'Comment message must not be empty',
                errorCode: 'comment_empty_message'
            );
        }

        $comment = $this->fetchComment(commentId: $commentId);
        $this->assertAuthorOrAdmin(
            comment: $comment,
            userId: $currentUserId
        );

        $comment->setMessage(message: $trimmed);
        // Marker for the serialiser — the underlying comment row stores
        // the original timestamp on the `creation_timestamp` column and
        // a separate `latest_child_message` field; we re-stamp the
        // comment's own `latestChildDateTime` to a fresh timestamp so
        // the serialiser can deterministically expose `wasEdited`.
        $comment->setLatestChildDateTime(dateTime: new DateTime());

        $this->commentsManager->save(comment: $comment);

        $this->parseAndResolveMentions(
            message: $trimmed,
            dashboardUuid: (string) $comment->getObjectId(),
            authorUserId: $currentUserId
        );

        return $comment;
    }//end updateComment()

    /**
     * Soft-delete a comment, cascading to replies for top-level deletes
     * (REQ-CMNT-005).
     *
     * @param int    $commentId     The comment ID.
     * @param string $currentUserId The acting user's ID.
     *
     * @return void
     *
     * @throws CommentNotFoundException  When the comment does not exist.
     * @throws CommentForbiddenException When the user is neither author
     *                                   nor admin.
     */
    public function deleteComment(
        int $commentId,
        string $currentUserId
    ): void {
        $comment = $this->fetchComment(commentId: $commentId);
        $this->assertAuthorOrAdmin(
            comment: $comment,
            userId: $currentUserId
        );

        // Cascade to direct children when deleting a top-level comment.
        if ((int) $comment->getParentId() === 0) {
            $children = $this->commentsManager->getForObject(
                objectType: self::OBJECT_TYPE,
                objectId: (string) $comment->getObjectId(),
                limit: 0,
                offset: 0
            );

            foreach ($children as $candidate) {
                if ((int) $candidate->getParentId() === (int) $comment->getId()) {
                    $this->commentsManager->delete(
                        id: (string) $candidate->getId()
                    );
                }
            }
        }

        $this->commentsManager->delete(id: (string) $comment->getId());
    }//end deleteComment()

    /**
     * Cascade entry point invoked when a dashboard is deleted (D6).
     *
     * Delegates to `ICommentsManager::deleteCommentsAtObject()` so all
     * comments and replies associated with the dashboard are removed in
     * a single backend call.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return void
     */
    public function deleteAllForDashboard(string $dashboardUuid): void
    {
        $this->commentsManager->deleteCommentsAtObject(
            objectType: self::OBJECT_TYPE,
            objectId: $dashboardUuid
        );
    }//end deleteAllForDashboard()

    /**
     * Parse `@username` mentions from a message, resolve them to
     * Nextcloud user IDs, and dispatch mention notifications
     * (REQ-CMNT-006).
     *
     * Returns the resolved mention list — `[{userId, displayName}, ...]`.
     * Unresolved mentions are silently dropped (no error, no
     * notification) so a typo in `@nonexistent_user` never breaks the
     * comment write path.
     *
     * @param string $message       The message text to parse.
     * @param string $dashboardUuid The dashboard UUID for the deep-link
     *                              attached to the notification.
     * @param string $authorUserId  The author's user ID — used as the
     *                              notification's "actor" so the
     *                              recipient knows who mentioned them.
     *
     * @return array<int, array{userId: string, displayName: string}>
     */
    public function parseAndResolveMentions(
        string $message,
        string $dashboardUuid,
        string $authorUserId
    ): array {
        $matches = [];
        $count   = preg_match_all(
            pattern: self::MENTION_REGEX,
            subject: $message,
            matches: $matches
        );

        if ($count === false) {
            return [];
        }

        if ($count < 1) {
            return [];
        }

        $unique = [];
        foreach ($matches[1] as $candidate) {
            // The regex group `([a-zA-Z0-9_.-]+)` guarantees one or more
            // characters, so the trimmed lowercase form is never empty
            // here — no length guard required.
            $normalised          = strtolower(string: (string) $candidate);
            $unique[$normalised] = true;
        }

        $resolved = [];
        foreach (array_keys($unique) as $username) {
            $user = $this->userManager->get(uid: (string) $username);
            if ($user === null) {
                continue;
            }

            $resolvedId  = (string) $user->getUID();
            $displayName = (string) $user->getDisplayName();

            $resolved[] = [
                'userId'      => $resolvedId,
                'displayName' => $displayName,
            ];

            // Skip self-mentions — no value in pinging yourself.
            if ($resolvedId === $authorUserId) {
                continue;
            }

            $this->dispatchMentionNotification(
                recipientUserId: $resolvedId,
                authorUserId: $authorUserId,
                dashboardUuid: $dashboardUuid
            );
        }//end foreach

        return $resolved;
    }//end parseAndResolveMentions()

    /**
     * Serialise a single comment to the JSON envelope contract
     * (REQ-CMNT-001 / REQ-CMNT-002).
     *
     * @param IComment $comment The comment to serialise.
     *
     * @return array<string, mixed> The wire-format envelope.
     */
    public function serialiseComment(IComment $comment): array
    {
        $createdAt = $comment->getCreationDateTime()->format(format: 'c');
        $latest    = $comment->getLatestChildDateTime();

        $updatedAt = null;
        if ($latest !== null) {
            $updatedAt = $latest->format(format: 'c');
        }

        $wasEdited = ($updatedAt !== null);

        $parentId = (int) $comment->getParentId();
        $parent   = null;
        if ($parentId !== 0) {
            $parent = $parentId;
        }

        return [
            'id'        => (int) $comment->getId(),
            'parentId'  => $parent,
            'author'    => (string) $comment->getActorId(),
            'message'   => (string) $comment->getMessage(),
            'createdAt' => $createdAt,
            'updatedAt' => $updatedAt,
            'wasEdited' => $wasEdited,
            'mentions'  => $this->parseAndResolveMentions(
                message: (string) $comment->getMessage(),
                dashboardUuid: (string) $comment->getObjectId(),
                authorUserId: (string) $comment->getActorId()
            ),
        ];
    }//end serialiseComment()

    /**
     * Look up a comment by ID, raising the local 404 exception on miss.
     *
     * @param int $commentId The comment ID.
     *
     * @return IComment The resolved comment.
     *
     * @throws CommentNotFoundException When the comment does not exist.
     */
    private function fetchComment(int $commentId): IComment
    {
        try {
            return $this->commentsManager->get(id: (string) $commentId);
        } catch (CommentManagerNotFoundException) {
            throw new CommentNotFoundException();
        }
    }//end fetchComment()

    /**
     * Enforce the author-or-admin guard on edit / delete (REQ-CMNT-004,
     * REQ-CMNT-005).
     *
     * @param IComment $comment The comment.
     * @param string   $userId  The acting user's ID.
     *
     * @return void
     *
     * @throws CommentForbiddenException When the user is neither author
     *                                   nor a Nextcloud admin.
     */
    private function assertAuthorOrAdmin(
        IComment $comment,
        string $userId
    ): void {
        if ((string) $comment->getActorId() === $userId) {
            return;
        }

        if ($this->groupManager->isAdmin(userId: $userId) === true) {
            return;
        }

        throw new CommentForbiddenException(
            message: 'Only the author or an admin may modify this comment'
        );
    }//end assertAuthorOrAdmin()

    /**
     * Dispatch a single @mention notification (REQ-CMNT-006).
     *
     * @param string $recipientUserId The mentioned user.
     * @param string $authorUserId    The comment author.
     * @param string $dashboardUuid   The dashboard UUID.
     *
     * @return void
     */
    private function dispatchMentionNotification(
        string $recipientUserId,
        string $authorUserId,
        string $dashboardUuid
    ): void {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp(app: Application::APP_ID)
            ->setUser(user: $recipientUserId)
            ->setDateTime(dateTime: new DateTime())
            ->setObject(
                type: self::OBJECT_TYPE,
                id: $dashboardUuid
            )
            ->setSubject(
                subject: self::NOTIFICATION_SUBJECT,
                parameters: [$authorUserId, $dashboardUuid]
            )
            ->setLink(
                link: $this->urlGenerator->linkToRouteAbsolute(
                    routeName: 'mydash.page.index'
                ).'?dashboard='.urlencode(string: $dashboardUuid)
            );

        $this->notificationManager->notify(notification: $notification);
    }//end dispatchMentionNotification()
}//end class
