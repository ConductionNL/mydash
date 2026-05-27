<?php

/**
 * ReactionService
 *
 * Service that mediates every read and write against the
 * `oc_mydash_dashboard_reactions` table. Owns the global/per-dashboard
 * toggle resolution, the admin emoji whitelist, idempotent add/remove
 * semantics, and the reactor-pagination shape. REQ-RXN-001..009.
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

use InvalidArgumentException;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\DashboardReaction;
use OCA\MyDash\Db\DashboardReactionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\IAppConfig;
use OCP\IUserManager;

/**
 * Service for managing dashboard emoji reactions.
 */
class ReactionService
{
    /**
     * Admin setting key — global on/off toggle. Default: true.
     *
     * @var string
     */
    public const KEY_ENABLED_DEFAULT = 'reactions_enabled_default';

    /**
     * Admin setting key — JSON array of allowed emoji.
     *
     * @var string
     */
    public const KEY_ALLOWED_EMOJIS = 'reactions_allowed_emojis';

    /**
     * Default reactor-pagination cap (REQ-RXN-004 — 100-item ceiling).
     *
     * @var integer
     */
    public const REACTORS_PAGE_SIZE = 100;

    /**
     * Factory default whitelist applied when the admin has not stored
     * a custom value. Matches the proposal default exactly.
     *
     * @var array<int, string>
     */
    public const DEFAULT_ALLOWED_EMOJIS = [
        '👍',
        '❤️',
        '🎉',
        '😂',
        '🤔',
        '😢',
    ];

    /**
     * Constructor
     *
     * @param DashboardReactionMapper $reactionMapper    The reaction mapper.
     * @param DashboardMapper         $dashboardMapper   Dashboard lookups
     *                                                   (toggle resolution).
     * @param PermissionService       $permissionService VIEW permission gate.
     * @param IAppConfig              $appConfig         App config — admin
     *                                                   settings.
     * @param IUserManager            $userManager       Display name
     *                                                   resolution for the
     *                                                   reactors-by-emoji
     *                                                   endpoint.
     */
    public function __construct(
        private readonly DashboardReactionMapper $reactionMapper,
        private readonly DashboardMapper $dashboardMapper,
        private readonly PermissionService $permissionService,
        private readonly IAppConfig $appConfig,
        private readonly IUserManager $userManager,
    ) {
    }//end __construct()

    /**
     * Resolve the effective reactions-enabled state for a dashboard.
     *
     * Resolution rules (REQ-RXN-005, REQ-RXN-006):
     *   - dashboard.reactionsEnabled === 1 → true
     *   - dashboard.reactionsEnabled === 0 → false
     *   - dashboard.reactionsEnabled === null → follow global setting
     *
     * @param Dashboard $dashboard The dashboard.
     *
     * @return bool True when reactions are effectively enabled.
     *
     * @spec openspec/specs/dashboard-reactions/spec.md
     */
    public function isReactionsEnabled(Dashboard $dashboard): bool
    {
        $perDash = $dashboard->getReactionsEnabled();
        if ($perDash === 1) {
            return true;
        }

        if ($perDash === 0) {
            return false;
        }

        return $this->isReactionsEnabledByDefault();
    }//end isReactionsEnabled()

    /**
     * Read the admin global on/off toggle. Default: true.
     *
     * @return bool True when the global default is on.
     *
     * @spec openspec/specs/dashboard-reactions/spec.md
     */
    public function isReactionsEnabledByDefault(): bool
    {
        return $this->appConfig->getValueBool(
            app: Application::APP_ID,
            key: self::KEY_ENABLED_DEFAULT,
            default: true
        );
    }//end isReactionsEnabledByDefault()

    /**
     * Read the admin emoji whitelist. Falls back to the factory default
     * when the setting is missing or corrupt.
     *
     * @return array<int, string> The allowed emoji list.
     *
     * @spec openspec/specs/dashboard-reactions/spec.md
     */
    public function getAllowedEmojis(): array
    {
        $raw = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: self::KEY_ALLOWED_EMOJIS,
            default: ''
        );

        if ($raw === '') {
            return self::DEFAULT_ALLOWED_EMOJIS;
        }

        $decoded = json_decode(json: $raw, associative: true);
        if (is_array($decoded) === false) {
            return self::DEFAULT_ALLOWED_EMOJIS;
        }

        $cleaned = [];
        foreach ($decoded as $entry) {
            if (is_string($entry) === true && $entry !== '') {
                $cleaned[] = $entry;
            }
        }

        // An admin-set empty list is intentional (per REQ-RXN-007
        // scenario "Empty emoji in whitelist") — surface the empty
        // list as-is rather than falling back to the default.
        return $cleaned;
    }//end getAllowedEmojis()

    /**
     * Throw when the supplied emoji is not in the allowed list.
     *
     * @param string $emoji The emoji to validate.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the emoji is empty or not
     *                                  whitelisted.
     *
     * @spec openspec/specs/dashboard-reactions/spec.md
     */
    public function validateEmoji(string $emoji): void
    {
        if ($emoji === '') {
            throw new InvalidArgumentException(message: 'Emoji not allowed');
        }

        $allowed = $this->getAllowedEmojis();
        if (in_array(needle: $emoji, haystack: $allowed, strict: true) === false) {
            throw new InvalidArgumentException(message: 'Emoji not allowed');
        }
    }//end validateEmoji()

    /**
     * Look up a dashboard by UUID and enforce the calling user's VIEW
     * permission. Used as the gate for every reaction endpoint.
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $userId        The acting user ID.
     *
     * @return Dashboard The resolved dashboard.
     *
     * @throws DoesNotExistException When the dashboard does not exist.
     * @throws PermissionDeniedException When the user cannot VIEW it.
     */
    private function loadAndAuthorise(
        string $dashboardUuid,
        string $userId
    ): Dashboard {
        $dashboard = $this->dashboardMapper->findByUuid(uuid: $dashboardUuid);
        if ($this->permissionService->canViewDashboard(
            userId: $userId,
            dashboardId: $dashboard->getId()
        ) === false
        ) {
            throw new PermissionDeniedException(
                message: 'Permission denied'
            );
        }

        return $dashboard;
    }//end loadAndAuthorise()

    /**
     * Add a reaction. Idempotent — re-posting the same emoji is a no-op
     * that returns the existing summary (REQ-RXN-001 scenario "User
     * re-posts the same emoji").
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $userId        The acting user ID.
     * @param string $emoji         The emoji to add.
     *
     * @return array The updated reactions summary.
     *
     * @throws DoesNotExistException     When the dashboard is missing.
     * @throws PermissionDeniedException When the user cannot VIEW.
     * @throws ReactionsDisabledException When reactions are off.
     * @throws InvalidArgumentException  When the emoji is not whitelisted.
     *
     * @spec openspec/specs/dashboard-reactions/spec.md
     */
    public function addReaction(
        string $dashboardUuid,
        string $userId,
        string $emoji
    ): array {
        $dashboard = $this->loadAndAuthorise(
            dashboardUuid: $dashboardUuid,
            userId: $userId
        );

        if ($this->isReactionsEnabled(dashboard: $dashboard) === false) {
            throw new ReactionsDisabledException(
                message: 'Reactions are disabled'
            );
        }

        $this->validateEmoji(emoji: $emoji);

        try {
            $this->reactionMapper->addReaction(
                dashboardUuid: $dashboardUuid,
                userId: $userId,
                emoji: $emoji
            );
        } catch (DbException $exception) {
            // Unique-constraint hit (REASON_UNIQUE_CONSTRAINT_VIOLATION = 4)
            // — swallow for idempotent semantics; any other DB failure
            // bubbles up so the controller can report 500.
            if ($exception->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $exception;
            }
        }

        return $this->buildSummary(dashboard: $dashboard, userId: $userId);
    }//end addReaction()

    /**
     * Remove a reaction. Idempotent — if no row matches, silently
     * succeeds (REQ-RXN-002 scenario "User attempts to remove a
     * reaction they did not make").
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $userId        The acting user ID.
     * @param string $emoji         The emoji to remove.
     *
     * @return bool True when a row was deleted, false when none matched.
     *
     * @throws DoesNotExistException     When the dashboard is missing.
     * @throws PermissionDeniedException When the user cannot VIEW.
     *
     * @spec openspec/specs/dashboard-reactions/spec.md
     */
    public function removeReaction(
        string $dashboardUuid,
        string $userId,
        string $emoji
    ): bool {
        $this->loadAndAuthorise(
            dashboardUuid: $dashboardUuid,
            userId: $userId
        );

        return $this->reactionMapper->removeReaction(
            dashboardUuid: $dashboardUuid,
            userId: $userId,
            emoji: $emoji
        );
    }//end removeReaction()

    /**
     * Build the `{counts, mine, enabled}` summary for a dashboard.
     *
     * When reactions are disabled (globally or per-dashboard) returns
     * `{counts: {}, mine: [], enabled: false}` regardless of stored
     * rows — REQ-RXN-003 scenario "Reactions disabled on dashboard".
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $userId        The acting user ID.
     *
     * @return array The summary.
     *
     * @throws DoesNotExistException     When the dashboard is missing.
     * @throws PermissionDeniedException When the user cannot VIEW.
     *
     * @spec openspec/specs/dashboard-reactions/spec.md
     */
    public function getReactionsSummary(
        string $dashboardUuid,
        string $userId
    ): array {
        $dashboard = $this->loadAndAuthorise(
            dashboardUuid: $dashboardUuid,
            userId: $userId
        );

        return $this->buildSummary(dashboard: $dashboard, userId: $userId);
    }//end getReactionsSummary()

    /**
     * Build the summary from a pre-resolved Dashboard entity. Internal
     * helper — every public path resolves through `loadAndAuthorise`
     * first.
     *
     * @param Dashboard $dashboard The dashboard.
     * @param string    $userId    The acting user ID.
     *
     * @return array The summary.
     */
    private function buildSummary(Dashboard $dashboard, string $userId): array
    {
        $enabled = $this->isReactionsEnabled(dashboard: $dashboard);
        if ($enabled === false) {
            return [
                'counts'  => (object) [],
                'mine'    => [],
                'enabled' => false,
            ];
        }

        $uuid   = (string) $dashboard->getUuid();
        $counts = $this->reactionMapper->countByEmoji(dashboardUuid: $uuid);
        $mine   = [];
        foreach ($this->reactionMapper->findByUser(
                userId: $userId,
                dashboardUuid: $uuid
            ) as $reaction
        ) {
            $emoji = $reaction->getEmoji();
            if ($emoji !== null) {
                $mine[] = $emoji;
            }
        }

        return [
            'counts'  => (object) $counts,
            'mine'    => $mine,
            'enabled' => true,
        ];
    }//end buildSummary()

    /**
     * List reactors for a single emoji on a dashboard, capped at
     * {@see self::REACTORS_PAGE_SIZE} per response with simple
     * offset cursor pagination (REQ-RXN-004).
     *
     * @param string      $dashboardUuid The dashboard UUID.
     * @param string      $emoji         The emoji.
     * @param string      $userId        The acting user ID.
     * @param string|null $cursor        Optional opaque offset cursor.
     *
     * @return array Page payload with `items`, `nextCursor`, `total`. The
     *               `items` entries are `{userId, displayName, reactedAt}`
     *               associative arrays.
     *
     * @throws DoesNotExistException     When the dashboard is missing.
     * @throws PermissionDeniedException When the user cannot VIEW.
     *
     * @spec openspec/specs/dashboard-reactions/spec.md
     */
    public function getReactorsByEmoji(
        string $dashboardUuid,
        string $emoji,
        string $userId,
        ?string $cursor=null
    ): array {
        $this->loadAndAuthorise(
            dashboardUuid: $dashboardUuid,
            userId: $userId
        );

        $offset = 0;
        if ($cursor !== null && $cursor !== '') {
            $candidate = (int) $cursor;
            if ($candidate > 0) {
                $offset = $candidate;
            }
        }

        $rows  = $this->reactionMapper->findByEmoji(
            dashboardUuid: $dashboardUuid,
            emoji: $emoji,
            limit: self::REACTORS_PAGE_SIZE,
            offset: $offset
        );
        $total = $this->reactionMapper->countReactorsByEmoji(
            dashboardUuid: $dashboardUuid,
            emoji: $emoji
        );

        $items = [];
        foreach ($rows as $reaction) {
            $reactorId = (string) $reaction->getUserId();
            $user      = $this->userManager->get(uid: $reactorId);
            $items[]   = [
                'userId'      => $reactorId,
                'displayName' => $user?->getDisplayName() ?? $reactorId,
                'reactedAt'   => $reaction->getReactedAtFormatted(),
            ];
        }

        $nextOffset = ($offset + count($items));
        $nextCursor = null;
        if ($nextOffset < $total) {
            $nextCursor = (string) $nextOffset;
        }

        return [
            'items'      => $items,
            'nextCursor' => $nextCursor,
            'total'      => $total,
        ];
    }//end getReactorsByEmoji()

    /**
     * Cascade-delete every reaction for a dashboard. Called by the
     * `ReactionsListener` (REQ-CSC-003) on `DashboardDeletedEvent`.
     * Returns the number of rows removed for log/observability.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return int The number of rows deleted.
     *
     * @spec openspec/specs/dashboard-reactions/spec.md
     */
    public function deleteReactionsByDashboard(string $dashboardUuid): int
    {
        return $this->reactionMapper->deleteByDashboardUuid(
            dashboardUuid: $dashboardUuid
        );
    }//end deleteReactionsByDashboard()
}//end class
