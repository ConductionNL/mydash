<?php

/**
 * AdminOrgNavigationController
 *
 * REST controller for the org-wide navigation tree
 * (REQ-ONAV-001..012). Two surfaces:
 *
 *  - `GET  /api/admin/org-navigation?lang={nl|en}` — any logged-in
 *    user reads the tree; the response is filtered by group
 *    visibility (REQ-ONAV-002).
 *  - `PUT  /api/admin/org-navigation?lang={nl|en}` — admin-only
 *    wholesale replace, validates, persists (REQ-ONAV-003).
 *  - `PUT  /api/admin/org-navigation/position`     — admin-only,
 *    persists the global `org_navigation_position` setting
 *    (REQ-ONAV-004).
 *  - `GET  /api/admin/org-navigation/position`     — any logged-in
 *    user reads the position so the panel knows where to render.
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
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Service\OrgNavigationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Org-wide navigation editor REST surface.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Service + group +
 *                                                 session +
 *                                                 setting mapper are
 *                                                 each used exactly
 *                                                 once.
 */
class AdminOrgNavigationController extends Controller
{
    /**
     * Setting key for the global navigation rail position
     * (REQ-ONAV-004). Stored in `mydash_admin_settings` rather than
     * `IAppData` because it is a scalar enum, not a tree.
     *
     * @var string
     */
    public const SETTING_KEY_POSITION = 'org_navigation_position';

    /**
     * Allowed values for the position setting (REQ-ONAV-004).
     *
     * @var array<int, string>
     */
    public const ALLOWED_POSITIONS = ['left', 'right', 'top', 'hidden'];

    /**
     * Default position when the setting is unset (REQ-ONAV-004).
     *
     * @var string
     */
    public const DEFAULT_POSITION = 'hidden';

    /**
     * Constructor.
     *
     * @param IRequest             $request      Inbound request.
     * @param OrgNavigationService $service      Tree storage + filter
     *                                           service.
     * @param AdminSettingMapper   $settings     Persistence layer for
     *                                           the position scalar.
     * @param IGroupManager        $groupManager Admin guard.
     * @param IUserSession         $userSession  Current user session.
     */
    public function __construct(
        IRequest $request,
        private readonly OrgNavigationService $service,
        private readonly AdminSettingMapper $settings,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * Read the org-navigation tree filtered for the current user.
     *
     * Accessible to any logged-in user (REQ-ONAV-002).
     *
     * @param string $lang Language code (defaults to `nl`).
     *
     * @return JSONResponse The filtered tree under `tree` plus the
     *                      effective `language`.
     *
     * @NoAdminRequired
     */
    /** @spec openspec/specs/navigation-editor-org/spec.md */
    public function getOrgNavigation(string $lang=OrgNavigationService::DEFAULT_LANGUAGE): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return ResponseHelper::unauthorized();
        }

        $language = $this->validateLanguage(language: $lang);
        if ($language === null) {
            return new JSONResponse(
                data: ['error' => 'Unsupported language'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $tree     = $this->service->getTree(language: $language);
        $filtered = $this->service->filterTreeByUserGroups(
            tree: $tree,
            userId: $user->getUID()
        );

        return ResponseHelper::success(
            data: [
                'tree'     => $filtered,
                'language' => $language,
            ]
        );
    }//end getOrgNavigation()

    /**
     * Replace the org-navigation tree for the given language.
     *
     * Admin-only (REQ-ONAV-003); validates and persists in one go.
     *
     * @param array|null $tree The full replacement tree.
     * @param string     $lang Language code (defaults to `nl`).
     *
     * @return JSONResponse The persisted tree (unchanged) on success.
     *
     * @NoAdminRequired
     */
    /** @spec openspec/specs/navigation-editor-org/spec.md */
    public function updateOrgNavigation(
        ?array $tree=null,
        string $lang=OrgNavigationService::DEFAULT_LANGUAGE
    ): JSONResponse {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        $language = $this->validateLanguage(language: $lang);
        if ($language === null) {
            return new JSONResponse(
                data: ['error' => 'Unsupported language'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if (is_array($tree) === false) {
            return new JSONResponse(
                data: ['error' => 'tree must be an array of node objects'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->service->setTree(tree: $tree, language: $language);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return ResponseHelper::success(
            data: [
                'tree'     => $tree,
                'language' => $language,
            ]
        );
    }//end updateOrgNavigation()

    /**
     * Read the global rail-position setting (REQ-ONAV-004).
     *
     * @return JSONResponse The current effective position.
     *
     * @NoAdminRequired
     */
    /** @spec openspec/specs/navigation-editor-org/spec.md */
    public function getPosition(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return ResponseHelper::unauthorized();
        }

        return ResponseHelper::success(
            data: ['position' => $this->readPosition()]
        );
    }//end getPosition()

    /**
     * Replace the global rail-position setting (REQ-ONAV-004).
     *
     * Admin-only. Accepts `{position: 'left'|'right'|'top'|'hidden'}`.
     *
     * @param string|null $position The desired position.
     *
     * @return JSONResponse The persisted position on success.
     *
     * @NoAdminRequired
     */
    /** @spec openspec/specs/navigation-editor-org/spec.md */
    public function updatePosition(?string $position=null): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        if ($position === null
            || in_array(needle: $position, haystack: self::ALLOWED_POSITIONS, strict: true) === false
        ) {
            return new JSONResponse(
                data: ['error' => 'position must be one of: '.implode(separator: ', ', array: self::ALLOWED_POSITIONS)],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $this->settings->setSetting(
            key: self::SETTING_KEY_POSITION,
            value: $position
        );

        return ResponseHelper::success(
            data: ['position' => $position]
        );
    }//end updatePosition()

    /**
     * Validate that a language code is one MyDash supports in v1.
     *
     * @param string $language The candidate language code.
     *
     * @return string|null The normalised language code, or `null`
     *                     when the value is not supported.
     */
    private function validateLanguage(string $language): ?string
    {
        $lower = strtolower(string: trim(string: $language));
        if (in_array(
            needle: $lower,
            haystack: OrgNavigationService::SUPPORTED_LANGUAGES,
            strict: true
        ) === false
        ) {
            return null;
        }

        return $lower;
    }//end validateLanguage()

    /**
     * Read the persisted position with a default fallback.
     *
     * @return string Always one of {@see self::ALLOWED_POSITIONS}.
     */
    private function readPosition(): string
    {
        $raw = $this->settings->getValue(
            key: self::SETTING_KEY_POSITION,
            default: self::DEFAULT_POSITION
        );

        if (is_string($raw) === false
            || in_array(needle: $raw, haystack: self::ALLOWED_POSITIONS, strict: true) === false
        ) {
            return self::DEFAULT_POSITION;
        }

        return $raw;
    }//end readPosition()

    /**
     * Verify the current session belongs to a Nextcloud admin.
     *
     * @return JSONResponse|null `null` when the caller is an admin;
     *                           a 401/403 response otherwise.
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->groupManager->isAdmin(userId: $user->getUID()) === false) {
            return ResponseHelper::forbidden(
                message: 'Administrator privileges required.'
            );
        }

        return null;
    }//end requireAdmin()
}//end class
