<?php

/**
 * WidgetApiController
 *
 * Controller for managing dashboard widgets.
 *
 * @category  Controller
 * @package   OCA\MyDash\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Controller;

use DateTimeImmutable;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\CalendarWidgetService;
use OCA\MyDash\Service\NewsWidgetService;
use OCA\MyDash\Service\WidgetService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Controller for managing dashboard widgets.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Routes for calendar
 *                                                 events, tiles, and
 *                                                 generic widget CRUD
 *                                                 share this controller.
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class WidgetApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest              $request               The request.
     * @param WidgetService         $widgetService         The widget service.
     * @param PermissionService     $permissionService     The permission service.
     * @param NewsWidgetService     $newsWidgetService     The news widget service.
     * @param CalendarWidgetService $calendarWidgetService The calendar widget service (REQ-CAL-003).
     * @param string|null           $userId                The user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly WidgetService $widgetService,
        private readonly PermissionService $permissionService,
        private readonly NewsWidgetService $newsWidgetService,
        private readonly CalendarWidgetService $calendarWidgetService,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * List all available Nextcloud widgets.
     *
     * @return JSONResponse The list of available widgets.
     *
     * @spec widgets:REQ-WDG-001
     */
    #[NoAdminRequired]
    public function listAvailable(): JSONResponse
    {
        return ResponseHelper::success(
            data: $this->widgetService->getAvailableWidgets()
        );
    }//end listAvailable()

    /**
     * Get widget items for specified widgets.
     *
     * @param array $widgets Array of widget IDs.
     * @param int   $limit   Maximum items per widget.
     *
     * @return JSONResponse The widget items.
     *
     * @spec widgets:REQ-WDG-002
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getItems(
        array $widgets=[],
        int $limit=7
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        return ResponseHelper::success(
            data: $this->widgetService->getWidgetItems(
                userId: $this->userId,
                widgetIds: $widgets,
                limit: $limit
            )
        );
    }//end getItems()

    /**
     * Add a widget to a dashboard.
     *
     * @param int    $dashboardId Dashboard ID.
     * @param string $widgetId    Widget ID.
     * @param int    $gridX       Grid X position.
     * @param int    $gridY       Grid Y position.
     * @param int    $gridWidth   Grid width.
     * @param int    $gridHeight  Grid height.
     *
     * @return JSONResponse The created widget placement.
     *
     * @spec widgets:REQ-WDG-003
     */
    #[NoAdminRequired]
    public function addWidget(
        int $dashboardId,
        string $widgetId,
        int $gridX=0,
        int $gridY=0,
        int $gridWidth=4,
        int $gridHeight=4
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->permissionService->canAddWidget(
            userId: $this->userId,
            dashboardId: $dashboardId
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        try {
            $placement = $this->widgetService->addWidget(
                dashboardId: $dashboardId,
                widgetId: $widgetId,
                gridX: $gridX,
                gridY: $gridY,
                gridWidth: $gridWidth,
                gridHeight: $gridHeight
            );

            return ResponseHelper::success(
                data: $placement->jsonSerialize(),
                statusCode: Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end addWidget()

    /**
     * Add a tile to a dashboard.
     *
     * @param int $dashboardId Dashboard ID.
     *
     * @return JSONResponse The created tile placement.
     */
    #[NoAdminRequired]
    public function addTile(int $dashboardId): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->permissionService->canAddWidget(
            userId: $this->userId,
            dashboardId: $dashboardId
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        try {
            $placement = $this->widgetService->addTileFromArray(
                dashboardId: $dashboardId,
                tileData: RequestDataExtractor::extractTileData(
                    request: $this->request
                )
            );

            return ResponseHelper::success(
                data: $placement->jsonSerialize(),
                statusCode: Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end addTile()

    /**
     * Update a widget placement.
     *
     * @param int $placementId The placement ID.
     *
     * @return JSONResponse The updated widget placement.
     *
     * @spec widgets:REQ-WDG-004
     */
    #[NoAdminRequired]
    public function updatePlacement(int $placementId): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->permissionService->canStyleWidget(
            userId: $this->userId,
            placementId: $placementId
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        try {
            $placement = $this->widgetService->updatePlacement(
                placementId: $placementId,
                data: RequestDataExtractor::extractPlacementData(
                    request: $this->request
                )
            );

            return ResponseHelper::success(
                data: $placement->jsonSerialize()
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end updatePlacement()

    /**
     * Remove a widget placement.
     *
     * @param int $placementId The placement ID.
     *
     * @return JSONResponse The removal confirmation.
     *
     * @spec widgets:REQ-WDG-005
     */
    #[NoAdminRequired]
    public function removePlacement(int $placementId): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($this->permissionService->canRemoveWidget(
            userId: $this->userId,
            placementId: $placementId
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        try {
            $this->widgetService->removePlacement(
                placementId: $placementId
            );

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end removePlacement()

    /**
     * Fetch merged news widget items for a placement (REQ-NEWS-003).
     *
     * Validates the caller, clamps the limit, and delegates to
     * {@see NewsWidgetService::getItemsForPlacement()}. The response
     * shape is `{items: array, feedsFailed: int, failedUrls: array}`
     * — the placement-level metadata filter is applied server-side
     * (REQ-NEWS-007), and a placement that fails the filter responds
     * with an empty items array (no HTTP fetch occurs).
     *
     * @param integer      $placementId Placement entity id.
     * @param integer|null $limit       Optional caller cap (default 10,
     *                                  rejected when outside [1, 50]).
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function newsItems(int $placementId, ?int $limit=10): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        $effectiveLimit = $limit;
        if ($effectiveLimit === null) {
            $effectiveLimit = 10;
        }

        if ($effectiveLimit < 1 || $effectiveLimit > 50) {
            return new JSONResponse(
                data: ['error' => 'limit out of range (1..50)'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if ($this->permissionService->canStyleWidget(
            userId: $this->userId,
            placementId: $placementId
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        $payload = $this->newsWidgetService->getItemsForPlacement(
            placementId: $placementId,
            limit: $effectiveLimit
        );

        return ResponseHelper::success(data: $payload);
    }//end newsItems()

    /**
     * Get aggregated events for a calendar-widget placement.
     *
     * Returns merged + sorted events from internal NC calendars and
     * external ICS feeds configured on the placement. The date range
     * is mandatory and is capped at one year in the controller as a
     * defensive measure against runaway RRULE expansion.
     *
     * REQ-CAL-003.
     *
     * @param int    $placementId The placement ID.
     * @param string $from        ISO 8601 start.
     * @param string $to          ISO 8601 end.
     *
     * @return JSONResponse The aggregated events payload.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function calendarEvents(
        int $placementId,
        string $from='',
        string $to=''
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        if ($from === '' || $to === '') {
            return new JSONResponse(
                data: ['error' => 'Both from and to are required ISO 8601 timestamps'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $start = new DateTimeImmutable(datetime: $from);
            $end   = new DateTimeImmutable(datetime: $to);
        } catch (Throwable $exception) {
            unset($exception);
            return new JSONResponse(
                data: ['error' => 'Invalid date format'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if ($end < $start) {
            return new JSONResponse(
                data: ['error' => '`to` must be greater than or equal to `from`'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        // Defensive 1-year cap per design D1 to bound RRULE expansion.
        $maxEnd = $start->modify(modifier: '+1 year');
        if ($end > $maxEnd) {
            $end = $maxEnd;
        }

        try {
            $placement = $this->widgetService->getPlacement(placementId: $placementId);
        } catch (Throwable $exception) {
            unset($exception);
            return new JSONResponse(
                data: ['error' => 'Placement not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        if ($this->permissionService->canStyleWidget(
            userId: $this->userId,
            placementId: $placementId
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        $config = $this->extractCalendarConfig(placement: $placement);

        try {
            $result = $this->calendarWidgetService->getEvents(
                config: $config,
                from: $start->format(format: \DATE_ATOM),
                to: $end->format(format: \DATE_ATOM)
            );
        } catch (\Exception $exception) {
            return ResponseHelper::error(exception: $exception);
        }

        return ResponseHelper::success(data: $result);
    }//end calendarEvents()

    /**
     * Pull `internalCalendars`/`externalIcsUrls` arrays out of the
     * placement's widgetContent JSON, applying defaults.
     *
     * @param object $placement The placement entity (WidgetPlacement).
     *
     * @return array{
     *     internalCalendars: array<int, string>,
     *     externalIcsUrls: array<int, string>,
     *     viewMode: string,
     *     daysAhead: int,
     *     colorByCalendar: bool
     * }
     */
    private function extractCalendarConfig(object $placement): array
    {
        $serialized = [];
        if (method_exists(object_or_class: $placement, method: 'jsonSerialize') === true) {
            $serialized = (array) $placement->jsonSerialize();
        }

        $widgetContent = $serialized['widgetContent'] ?? $serialized['content'] ?? [];

        if (is_string(value: $widgetContent) === true) {
            $decoded       = json_decode(json: $widgetContent, associative: true);
            $widgetContent = [];
            if (is_array(value: $decoded) === true) {
                $widgetContent = $decoded;
            }
        }

        $widgetContent = (array) $widgetContent;

        $internal = (array) ($widgetContent['internalCalendars'] ?? []);
        $external = (array) ($widgetContent['externalIcsUrls'] ?? []);

        // Cast every entry to string for safety; drop empty strings.
        $internal = array_values(
                array: array_filter(
            array: array_map(callback: 'strval', array: $internal),
            callback: static fn(string $value): bool => $value !== ''
        )
                );
        $external = array_values(
                array: array_filter(
            array: array_map(callback: 'strval', array: $external),
            callback: static fn(string $value): bool => $value !== ''
        )
                );

        return [
            'internalCalendars' => $internal,
            'externalIcsUrls'   => $external,
            'viewMode'          => (string) ($widgetContent['viewMode'] ?? 'agenda'),
            'daysAhead'         => (int) ($widgetContent['daysAhead'] ?? 14),
            'colorByCalendar'   => (bool) ($widgetContent['colorByCalendar'] ?? true),
        ];
    }//end extractCalendarConfig()
}//end class
