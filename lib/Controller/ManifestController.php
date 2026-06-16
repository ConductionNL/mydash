<?php

/**
 * ManifestController
 *
 * Serves the v2 app manifest for the current user. The manifest is assembled
 * at runtime from the user's dashboard objects stored in OpenRegister
 * (ADR-036 Decision 8). Each OR dashboard object becomes one `type: "dashboard"`
 * page entry and one menu entry. When the user has no dashboards the manifest
 * returns empty pages/menu so the frontend renders its empty-state CTA.
 *
 * @category  Controller
 * @package   OCA\MyDash\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Controller;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\ActionAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for the v2 runtime manifest endpoint (ADR-036 Decision 8).
 *
 * Assembles a per-user app manifest from the user's dashboard objects
 * stored in OpenRegister and returns it as a JSON document that validates
 * against the v2 schema.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ManifestController extends Controller
{
    /**
     * OpenRegister register slug for mydash dashboards.
     *
     * @var string
     */
    private const REGISTER = 'mydash';

    /**
     * OpenRegister schema slug for dashboard objects.
     *
     * @var string
     */
    private const SCHEMA = 'dashboard';

    /**
     * V2 manifest schema URL.
     *
     * @var string
     */
    private const SCHEMA_URL = 'https://raw.githubusercontent.com/ConductionNL/nextcloud-vue/main/src/schemas/app-manifest-v2.schema.json';

    /**
     * Constructor.
     *
     * @param IRequest           $request     The HTTP request.
     * @param ContainerInterface $container   The Nextcloud DI container; used to
     *                                        lazy-load ObjectService so that mydash
     *                                        degrades gracefully when OpenRegister
     *                                        is not yet active.
     * @param ActionAuthService  $actionAuth  ADR-023 action authorization.
     * @param IUserSession       $userSession User session (IUser resolution).
     * @param LoggerInterface    $logger      PSR logger.
     * @param string|null        $userId      The authenticated user ID, injected
     *                                        by the DI container.
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly ActionAuthService $actionAuth,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * Build and return the v2 app manifest for the authenticated user.
     *
     * Reads the user's dashboard objects from OpenRegister. Each object with
     * a `slug` and `title` property becomes one page entry and one menu entry.
     * Objects the user owns OR that list the user in `sharedWith` are included.
     *
     * Route: GET /apps/mydash/api/manifest
     *
     * @return JSONResponse A JSON document conforming to the v2 manifest
     *                      schema. Returns HTTP 401 when no user is
     *                      authenticated, HTTP 503 when OpenRegister is
     *                      unavailable.
     *
     * @spec manifest-v2-runtime:REQ-MVR-001
     * @spec openspec/specs/runtime-shell/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction($user, 'manifest.index');
        } catch (OCSForbiddenException) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        // Retrieve ObjectService lazily — OpenRegister may not be enabled on
        // every instance. Returning an empty manifest (not an error) lets the
        // frontend render its "no dashboards yet" CTA without a red alert.
        try {
            /*
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'MyDash: OpenRegister ObjectService unavailable — returning empty manifest. '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );

            return new JSONResponse($this->buildManifest(dashboards: [], userId: $this->userId));
        }//end try

        // Fetch all dashboard objects owned by or shared with the current user.
        $dashboards = $this->fetchUserDashboards(objectService: $objectService, userId: $this->userId);

        return new JSONResponse($this->buildManifest(dashboards: $dashboards, userId: $this->userId));

    }//end index()

    /**
     * Fetch dashboard objects from OpenRegister for the given user.
     *
     * C5 fix (REQ-MVR-001): replaces the non-existent `findObjects()` call
     * (which caused a BadMethodCallException silently swallowed by Throwable)
     * with the real `ObjectService::findAll()` API. The `owner` filter
     * constrains results to the calling user's records — without it every
     * user would receive the full dataset (latent IDOR on top of the API drift).
     *
     * @param object $objectService The OpenRegister ObjectService instance.
     * @param string $userId        The authenticated Nextcloud user ID.
     *
     * @return array<int, array<string, mixed>> Flat list of dashboard data arrays.
     */
    private function fetchUserDashboards(object $objectService, string $userId): array
    {
        $dashboards = [];
        $seen       = [];

        try {
            // C5 fix: use the real ObjectService::findAll() API.
            // `findObjects()` does not exist; the old call threw
            // BadMethodCallException that was silently swallowed, causing the
            // manifest to always return empty pages/menu arrays.
            $ownedResults = $objectService->findAll(
                config: [
                    'filters' => [
                        'register' => self::REGISTER,
                        'schema'   => self::SCHEMA,
                        'owner'    => $userId,
                    ],
                    'limit'   => 500,
                ]
            );

            if (is_array($ownedResults) === true) {
                foreach ($ownedResults as $item) {
                    $data = $this->extractData(item: $item);
                    if (empty($data) === true) {
                        continue;
                    }

                    $id = $data['id'] ?? $data['uuid'] ?? $data['slug'] ?? null;
                    if ($id !== null && isset($seen[$id]) === false) {
                        $seen[$id]    = true;
                        $dashboards[] = $data;
                    }
                }
            }
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            // Narrow catch: only handle recoverable OR API errors. Let
            // unexpected errors propagate so they are visible in the logs.
            $this->logger->error(
                'MyDash: failed to fetch dashboards from OpenRegister: '.$e->getMessage(),
                ['app' => Application::APP_ID, 'userId' => $userId]
            );
        }//end try

        return $dashboards;

    }//end fetchUserDashboards()

    /**
     * Normalise a raw ObjectService result item to a plain data array.
     *
     * OpenRegister items may be returned as objects with a `getObject()`
     * method or as plain associative arrays.
     *
     * @param mixed $item A single result from ObjectService::findAll().
     *
     * @return array<string, mixed> The plain data array, or [] on failure.
     */
    private function extractData(mixed $item): array
    {
        if (is_array($item) === true) {
            return $item;
        }

        if (is_object($item) === true && method_exists($item, 'getObject') === true) {
            $data = $item->getObject();
            if (is_array($data) === true) {
                return $data;
            }

            return [];
        }

        if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
            $data = $item->jsonSerialize();
            if (is_array($data) === true) {
                return $data;
            }

            return [];
        }

        return [];

    }//end extractData()

    /**
     * Build the v2 manifest array from a list of dashboard data arrays.
     *
     * @param array<int, array<string, mixed>> $dashboards Flat list of dashboard data.
     * @param string                           $userId     The current user ID.
     *
     * @return array<string, mixed> The v2 manifest document.
     */
    private function buildManifest(array $dashboards, string $userId): array
    {
        $pages = [];
        $menu  = [];
        $order = 0;

        foreach ($dashboards as $data) {
            $slug  = $data['slug'] ?? null;
            $title = $data['title'] ?? null;

            if (empty($slug) === true || empty($title) === true) {
                continue;
            }

            $pageId = 'dashboard-'.$slug;

            $pages[] = [
                'id'      => $pageId,
                'route'   => '/'.$slug,
                'type'    => 'dashboard',
                'title'   => $title,
                'widgets' => $data['widgets'] ?? [],
            ];

            $menu[] = [
                'id'    => 'menu-'.$slug,
                'label' => $title,
                'route' => $pageId,
                'order' => $order,
                'icon'  => 'icon-home',
            ];

            $order++;
        }//end foreach

        return [
            '$schema'      => self::SCHEMA_URL,
            'version'      => '1.0.0',
            'dependencies' => ['openregister'],
            'menu'         => $menu,
            'pages'        => $pages,
            'runtime'      => [
                'user' => [
                    'id' => $userId,
                ],
            ],
        ];

    }//end buildManifest()
}//end class
