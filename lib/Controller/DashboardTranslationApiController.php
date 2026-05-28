<?php

/**
 * DashboardTranslationApiController
 *
 * Controller for the per-language dashboard content variant endpoints —
 * create, update, delete, promote-primary, plus the read-side resolver
 * exposed via `GET /api/dashboards/{uuid}` with optional `?lang=` query
 * parameter. REQ-DASH-038..044 (dashboard-language-content).
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

use Exception;
use InvalidArgumentException;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Service\DashboardTranslationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for dashboard translation endpoints (REQ-DASH-038..044).
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DashboardTranslationApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest                    $request            The request.
     * @param DashboardMapper             $dashboardMapper    Dashboard mapper
     *                                                        (used for the
     *                                                        ownership check
     *                                                        before any
     *                                                        translation
     *                                                        mutation).
     * @param DashboardTranslationService $translationService Translation
     *                                                        service.
     * @param string|null                 $userId             The user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly DashboardMapper $dashboardMapper,
        private readonly DashboardTranslationService $translationService,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * GET /api/dashboards/{uuid}/translations — list every translation
     * variant for a dashboard. Returns 403 when the dashboard belongs
     * to another user. REQ-DASH-038.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return JSONResponse The list payload.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-language-content/spec.md */
    public function list(string $uuid): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $ownerCheck = $this->assertOwner(uuid: $uuid);
        if ($ownerCheck !== null) {
            return $ownerCheck;
        }

        $variants = $this->translationService->listVariants(
            dashboardUuid: $uuid
        );

        $serialized = ResponseHelper::serializeList(entities: $variants);

        return ResponseHelper::success(
            data: ['translations' => $serialized]
        );
    }//end list()

    /**
     * POST /api/dashboards/{uuid}/translations — create a new variant.
     *
     * Body: `{languageCode, name?, description?, widgetTreeJson?, copyFrom?}`.
     * Returns 201 with the created entity. Maps duplicate-language
     * conflicts to HTTP 409. REQ-DASH-040.
     *
     * @param string      $uuid           The dashboard UUID.
     * @param string|null $languageCode   The language code from the body.
     * @param string|null $name           The optional name.
     * @param string|null $description    The optional description.
     * @param string|null $widgetTreeJson The optional widget tree JSON.
     * @param string|null $copyFrom       Optional source language.
     *
     * @return JSONResponse The created variant.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-language-content/spec.md */
    public function create(
        string $uuid,
        ?string $languageCode=null,
        ?string $name=null,
        ?string $description=null,
        ?string $widgetTreeJson=null,
        ?string $copyFrom=null
    ): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $ownerCheck = $this->assertOwner(uuid: $uuid);
        if ($ownerCheck !== null) {
            return $ownerCheck;
        }

        if ($languageCode === null || $languageCode === '') {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_argument',
                    'message' => DashboardTranslationService::ERR_INVALID_LANGUAGE,
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $variant = $this->translationService->createVariant(
                dashboardUuid: $uuid,
                languageCode: $languageCode,
                name: $name,
                description: $description,
                widgetTreeJson: $widgetTreeJson,
                copyFromLanguage: $copyFrom
            );

            return new JSONResponse(
                data: ['translation' => $variant->jsonSerialize()],
                statusCode: Http::STATUS_CREATED
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => 'invalid_argument',
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (Exception $e) {
            if ($e->getMessage() === DashboardTranslationService::ERR_LANGUAGE_EXISTS) {
                return new JSONResponse(
                    data: [
                        'status'  => 'error',
                        'error'   => 'language_exists',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: Http::STATUS_CONFLICT
                );
            }

            return ResponseHelper::error(exception: $e);
        }//end try
    }//end create()

    /**
     * PUT /api/dashboards/{uuid}/translations/{lang} — update a variant.
     *
     * Body: `{name?, description?, widgetTreeJson?}`. Returns 200 with
     * the updated entity. REQ-DASH-041.
     *
     * @param string      $uuid           The dashboard UUID.
     * @param string      $lang           The language code from the URL.
     * @param string|null $name           Optional new name.
     * @param string|null $description    Optional new description.
     * @param string|null $widgetTreeJson Optional new widget tree JSON.
     *
     * @return JSONResponse The updated variant.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-language-content/spec.md */
    public function update(
        string $uuid,
        string $lang,
        ?string $name=null,
        ?string $description=null,
        ?string $widgetTreeJson=null
    ): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $ownerCheck = $this->assertOwner(uuid: $uuid);
        if ($ownerCheck !== null) {
            return $ownerCheck;
        }

        $patch = $this->buildPatch(
            name: $name,
            description: $description,
            widgetTreeJson: $widgetTreeJson
        );

        try {
            $variant = $this->translationService->updateVariant(
                dashboardUuid: $uuid,
                languageCode: $lang,
                patch: $patch
            );

            return ResponseHelper::success(
                data: ['translation' => $variant->jsonSerialize()]
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: [
                    'status' => 'error',
                    'error'  => 'not_found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end update()

    /**
     * DELETE /api/dashboards/{uuid}/translations/{lang} — delete a
     * variant. Maps last-variant / primary-variant guards to HTTP 400.
     * REQ-DASH-042.
     *
     * @param string $uuid The dashboard UUID.
     * @param string $lang The language code from the URL.
     *
     * @return JSONResponse The status payload.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-language-content/spec.md */
    public function destroy(string $uuid, string $lang): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $ownerCheck = $this->assertOwner(uuid: $uuid);
        if ($ownerCheck !== null) {
            return $ownerCheck;
        }

        try {
            $this->translationService->deleteVariant(
                dashboardUuid: $uuid,
                languageCode: $lang
            );

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: [
                    'status' => 'error',
                    'error'  => 'not_found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (Exception $e) {
            $errorCode = 'invalid_state';
            if ($e->getMessage() === DashboardTranslationService::ERR_LAST_VARIANT) {
                $errorCode = 'last_variant';
            } else if ($e->getMessage() === DashboardTranslationService::ERR_DELETE_PRIMARY) {
                $errorCode = 'primary_variant';
            }

            return new JSONResponse(
                data: [
                    'status'  => 'error',
                    'error'   => $errorCode,
                    'message' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end destroy()

    /**
     * POST /api/dashboards/{uuid}/translations/{lang}/set-primary —
     * promote a variant to primary. Idempotent. REQ-DASH-043.
     *
     * @param string $uuid The dashboard UUID.
     * @param string $lang The language code from the URL.
     *
     * @return JSONResponse The promoted variant.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-language-content/spec.md */
    public function setPrimary(string $uuid, string $lang): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $ownerCheck = $this->assertOwner(uuid: $uuid);
        if ($ownerCheck !== null) {
            return $ownerCheck;
        }

        try {
            $variant = $this->translationService->promoteVariantToPrimary(
                dashboardUuid: $uuid,
                languageCode: $lang
            );

            return ResponseHelper::success(
                data: ['translation' => $variant->jsonSerialize()]
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: [
                    'status' => 'error',
                    'error'  => 'not_found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end setPrimary()

    /**
     * GET /api/dashboards/{uuid}/resolved — resolve the dashboard's
     * content for the viewer's locale. Optional `?lang=<code>` query
     * parameter overrides the user's Nextcloud locale; in strict mode
     * an unknown explicit lang returns 404 instead of falling back.
     * REQ-DASH-039.
     *
     * Response shape:
     *  - `dashboard`: the dashboard entity payload
     *  - `translation`: the matched translation row
     *  - `availableLanguages`: sorted list of codes
     *  - `currentLanguage`: the matched code
     *  - `isFallback`: true when the primary fallback was used
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return JSONResponse The resolved payload.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-language-content/spec.md */
    public function resolved(string $uuid): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: [
                    'status' => 'error',
                    'error'  => 'not_found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $explicitLang = $this->request->getParam(key: 'lang');
        $hasExplicit  = is_string($explicitLang) === true && $explicitLang !== '';

        if ($hasExplicit === true) {
            $variant = $this->translationService->resolveForLocale(
                dashboardUuid: $uuid,
                preferredLanguage: $explicitLang
            );

            // Strict mode for explicit lang param — when no exact match
            // exists for the requested code, return 404 instead of the
            // primary-fallback envelope. REQ-DASH-039 strict scenario.
            $requested = \OCA\MyDash\Db\DashboardTranslationMapper::normaliseLanguageCode(
                raw: $explicitLang
            );
            $matched   = null;
            if ($variant !== null) {
                $matched = (string) $variant['translation']->getLanguageCode();
            }

            if ($variant === null || $matched !== $requested) {
                return new JSONResponse(
                    data: [
                        'status' => 'error',
                        'error'  => 'language_not_available',
                    ],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }
        } else {
            $variant = $this->translationService->resolveForLocale(
                dashboardUuid: $uuid,
                preferredLanguage: ''
            );

            // Legacy fallback — dashboards predating REQ-DASH-038 may
            // have no translation rows yet. Materialise an in-memory
            // variant from the dashboard's own fields so the response
            // envelope shape stays uniform. REQ-DASH-044.
            if ($variant === null) {
                $variant = [
                    'translation' => $this->translationService
                        ->materialiseLegacyVariant(dashboard: $dashboard),
                    'isFallback'  => true,
                ];
            }
        }//end if

        $available = $this->translationService->listAvailableLanguages(
            dashboardUuid: $uuid
        );
        if (count($available) === 0) {
            $code = (string) $variant['translation']->getLanguageCode();
            if ($code !== '') {
                $available = [$code];
            }
        }

        return ResponseHelper::success(
            data: [
                'dashboard'          => $dashboard->jsonSerialize(),
                'translation'        => $variant['translation']->jsonSerialize(),
                'availableLanguages' => $available,
                'currentLanguage'    => $variant['translation']->getLanguageCode(),
                'isFallback'         => $variant['isFallback'],
            ]
        );
    }//end resolved()

    /**
     * Look up the dashboard and verify the current user is the owner.
     *
     * Returns null when the check passes; an HTTP 403 / 404 envelope
     * when it fails. Group-shared dashboards are not addressable via
     * the personal-scope translation endpoints — they short-circuit to
     * 403 (the group-scoped translation flow lives separately).
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return JSONResponse|null The error envelope or null on success.
     */
    private function assertOwner(string $uuid): ?JSONResponse
    {
        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: [
                    'status' => 'error',
                    'error'  => 'not_found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        if ($dashboard->getUserId() !== $this->userId) {
            return ResponseHelper::forbidden();
        }

        return null;
    }//end assertOwner()

    /**
     * Build the patch payload from individual nullable parameters.
     *
     * `null` means "not in payload" (skip the key); anything else (incl.
     * the empty string) means "set it explicitly". The service then
     * inspects key presence with `array_key_exists`.
     *
     * @param string|null $name           The new name.
     * @param string|null $description    The new description.
     * @param string|null $widgetTreeJson The new widget tree JSON.
     *
     * @return array The patch payload.
     */
    private function buildPatch(
        ?string $name,
        ?string $description,
        ?string $widgetTreeJson
    ): array {
        $patch = [];
        if ($name !== null) {
            $patch['name'] = $name;
        }

        if ($description !== null) {
            $patch['description'] = $description;
        }

        if ($widgetTreeJson !== null) {
            $patch['widgetTreeJson'] = $widgetTreeJson;
        }

        return $patch;
    }//end buildPatch()
}//end class
