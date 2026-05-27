<?php

/**
 * PeopleWidgetController
 *
 * HTTP entry point for the People widget user-listing API. Thin adapter
 * that parses query parameters, delegates to {@see PeopleWidgetService},
 * and converts thrown {@see InvalidArgumentException}s to HTTP 400.
 *
 * Route: `GET /api/people` — declared in {@see appinfo/routes.php}.
 *
 * @category  Controller
 * @package   OCA\MyDash\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Controller;

use InvalidArgumentException;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\PeopleWidgetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * REST controller exposing the paginated People widget directory.
 */
class PeopleWidgetController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest            $request HTTP request.
     * @param PeopleWidgetService $service People widget service.
     * @param LoggerInterface     $logger  Logger for ResponseHelper::error.
     * @param string|null         $userId  Active user (null when anonymous).
     */
    public function __construct(
        IRequest $request,
        private readonly PeopleWidgetService $service,
        private readonly LoggerInterface $logger,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * GET /api/people
     *
     * REQ-PPL-003: returns `{users, total, hasMore}`, offset-based
     * pagination, capped at {@see PeopleWidgetService::MAX_LIMIT}.
     *
     * Request parameters:
     *  - `filters`         JSON-encoded array of FilterObject entries
     *                      (see REQ-PPL-002 / REQ-PPL-006).
     *  - `excludeDisabled` 1/0 boolean (default 1).
     *  - `showBirthdays`   1/0 boolean (default 1).
     *  - `sortBy`          One of `displayName` (default), `group`,
     *                      `recent-activity` (rejected with 400).
     *  - `limit`           1..100 (default 50).
     *  - `offset`          >= 0 (default 0).
     *
     * @param string|null $filters         JSON-encoded filter list.
     * @param int|null    $excludeDisabled 1 to exclude disabled users.
     * @param int|null    $showBirthdays   1 to include birthdate field.
     * @param string|null $sortBy          Sort key.
     * @param int|null    $limit           Page size.
     * @param int|null    $offset          Page offset.
     *
     * @return JSONResponse
         *
     * @spec openspec/specs/people-widget/spec.md
 */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getUsers(
        ?string $filters=null,
        ?int $excludeDisabled=1,
        ?int $showBirthdays=1,
        ?string $sortBy='displayName',
        ?int $limit=PeopleWidgetService::DEFAULT_LIMIT,
        ?int $offset=0,
    ): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $parsedFilters = $this->parseFilters(raw: $filters);
        } catch (InvalidArgumentException $e) {
            return ResponseHelper::error(
                exception: $e,
                statusCode: Http::STATUS_BAD_REQUEST,
                logger: $this->logger,
                message: 'Invalid filters parameter'
            );
        }

        try {
            $payload = $this->service->listUsers(
                filters: $parsedFilters,
                excludeDisabled: ($excludeDisabled ?? 1) === 1,
                showBirthdays: ($showBirthdays ?? 1) === 1,
                sortBy: ($sortBy ?? 'displayName'),
                limit: ($limit ?? PeopleWidgetService::DEFAULT_LIMIT),
                offset: ($offset ?? 0),
            );
        } catch (InvalidArgumentException $e) {
            return ResponseHelper::error(
                exception: $e,
                statusCode: Http::STATUS_BAD_REQUEST,
                logger: $this->logger,
                message: 'Invalid request parameters'
            );
        }

        return ResponseHelper::success(data: $payload);
    }//end getUsers()

    /**
     * Parse the JSON-encoded `filters` query parameter. Returns `[]` when
     * the parameter is absent or an empty string. Throws on malformed
     * JSON or on a top-level non-array.
     *
     * Per-entry shape validation is intentionally permissive: the service
     * layer ignores unknown filter keys, so extra fields are tolerated.
     *
     * @param string|null $raw The raw query string value.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException When the JSON is malformed or the
     *                                  decoded value is not an array.
     */
    private function parseFilters(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode(json: $raw, associative: true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                message: 'filters: malformed JSON ('.json_last_error_msg().')'
            );
        }

        if (is_array(value: $decoded) === false) {
            throw new InvalidArgumentException(
                message: 'filters: expected JSON array'
            );
        }

        // Re-key so PHPStan is happy with the array<int, ...> shape.
        return array_values(array: $decoded);
    }//end parseFilters()
}//end class
