<?php

/**
 * AdminSettingsController
 *
 * HTTP entry point for admin-only group-priority order management
 * (REQ-ASET-012, REQ-ASET-013, REQ-ASET-014).
 *
 * Exposes two endpoints:
 *  - `GET  /api/admin/groups` returning `{active, inactive, allKnown}`
 *    so the admin UI can render both columns in one round-trip and
 *    surface stale (deleted) group IDs in the active list.
 *  - `POST /api/admin/groups` body `{groups: string[]}` replacing the
 *    persisted setting wholesale (no merge — UI sends the full ordered
 *    list after every drag).
 *
 * Both endpoints are admin-only via `IGroupManager::isAdmin` because
 * even the GET reveals every group on the system (privacy concern).
 *
 * @category  Controller
 * @package   OCA\MyDash\Controller
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

namespace OCA\MyDash\Controller;

use InvalidArgumentException;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\AdminSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin-only controller for the group-priority order setting.
 */
class AdminSettingsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request         The HTTP request.
     * @param AdminSettingsService $settingsService Persisted-settings service.
     * @param IGroupManager        $groupManager    Group manager (admin check + listing).
     * @param IUserSession         $userSession     Active session accessor.
     */
    public function __construct(
        IRequest $request,
        private readonly AdminSettingsService $settingsService,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * Handle `GET /api/admin/groups` — REQ-ASET-013.
     *
     * Returns the disjoint exhaustive split `{active, inactive, allKnown}`:
     *  - `active` — the persisted `group_order` list, in admin-chosen
     *    order. Stale IDs (no longer in Nextcloud) remain so admin can
     *    see and remove them.
     *  - `inactive` — every Nextcloud group ID NOT in `active`, sorted
     *    by displayName (case-insensitive).
     *  - `allKnown` — full `{id, displayName}` list for the UI to render
     *    display names without a second round-trip. Stale IDs MUST NOT
     *    appear here (no display name available).
     *
     * @return JSONResponse Either the success payload or HTTP 403 when
     *                      the caller is not an administrator.
     */
    public function listGroups(): JSONResponse
    {
        $forbidden = $this->assertAdmin();
        if ($forbidden !== null) {
            return $forbidden;
        }

        $allKnown    = [];
        $allKnownIds = [];
        foreach ($this->groupManager->search(search: '') as $group) {
            $id            = $group->getGID();
            $allKnownIds[] = $id;
            $allKnown[]    = [
                'id'          => $id,
                'displayName' => $group->getDisplayName(),
            ];
        }

        $active = $this->settingsService->getGroupOrder();

        // `inactive` = allKnown - active (stale active IDs MUST NOT
        // appear in inactive — REQ-ASET-013 disjoint scenario).
        $activeSet = array_flip(array: $active);
        $inactive  = [];
        foreach ($allKnownIds as $id) {
            if (array_key_exists(key: $id, array: $activeSet) === false) {
                $inactive[] = $id;
            }
        }

        // Sort `inactive` by displayName (case-insensitive). Build a
        // lookup so stable sort by name is cheap.
        $displayNameById = [];
        foreach ($allKnown as $row) {
            $displayNameById[$row['id']] = $row['displayName'];
        }

        usort(
            array: $inactive,
            callback: static function (string $aId, string $bId) use ($displayNameById): int {
                $aName = strtolower(string: $displayNameById[$aId] ?? $aId);
                $bName = strtolower(string: $displayNameById[$bId] ?? $bId);
                return strcmp(string1: $aName, string2: $bName);
            }
        );

        return ResponseHelper::success(
            data: [
                'active'   => $active,
                'inactive' => $inactive,
                'allKnown' => $allKnown,
            ]
        );
    }//end listGroups()

    /**
     * Handle `POST /api/admin/groups` — REQ-ASET-012, REQ-ASET-014.
     *
     * Body: `{"groups": ["id", ...]}`. Replaces the persisted
     * `group_order` setting wholesale. Validation:
     *  - `groups` MUST be present and an array.
     *  - Every element MUST be a non-empty string.
     *  - Duplicate IDs are deduplicated (first occurrence kept).
     *  - Unknown (not currently in Nextcloud) IDs are tolerated — they
     *    remain in the persisted setting per REQ-ASET-014.
     *
     * @param mixed $groups The raw `groups` payload from the request body.
     *
     * @return JSONResponse HTTP 200 with `{status: 'ok'}` on success,
     *                      400 on validation failure, 403 for non-admins.
     */
    public function updateGroupOrder(mixed $groups=null): JSONResponse
    {
        $forbidden = $this->assertAdmin();
        if ($forbidden !== null) {
            return $forbidden;
        }

        if (is_array($groups) === false) {
            return new JSONResponse(
                data: ['error' => 'groups must be an array'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->settingsService->setGroupOrder(groupIds: $groups);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return ResponseHelper::success(data: ['status' => 'ok']);
    }//end updateGroupOrder()

    /**
     * Assert that the active session belongs to an administrator.
     *
     * Both endpoints are admin-only because the inactive list reveals
     * every group on the system (REQ-ASET-014). The base controller
     * routing already requires authentication; this guard only adds the
     * admin check on top.
     *
     * @return JSONResponse|null `null` when the caller is an admin, or
     *                           a 403 response that the calling action
     *                           should return verbatim.
     */
    private function assertAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return ResponseHelper::forbidden();
        }

        if ($this->groupManager->isAdmin(userId: $user->getUID()) === false) {
            return ResponseHelper::forbidden();
        }

        return null;
    }//end assertAdmin()
}//end class
