<?php

/**
 * FeedController
 *
 * HTTP surface for the per-user RSS / Atom feed-token capability
 * (REQ-FEED-001..009). Splits cleanly into:
 *
 *  - {@see self::getToken()}        – `GET /api/feed/token`
 *  - {@see self::regenerateToken()} – `POST /api/feed/token/regenerate`
 *  - {@see self::revokeToken()}     – `DELETE /api/feed/token`
 *  - {@see self::publicFeed()}      – `GET /feed/{token}.xml` (no auth)
 *
 * The first three require a logged-in user (`#[NoAdminRequired]`); the
 * fourth is a public route protected only by the opaque token in the
 * URL path (REQ-FEED-008 — opt-in feed gating).
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

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\FeedToken;
use OCA\MyDash\Service\FeedService;
use OCA\MyDash\Service\FeedTokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Throwable;

/**
 * Controller for the RSS / Atom feed endpoints.
 */
class FeedController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request      The request.
     * @param FeedTokenService $tokenService Token-management service.
     * @param FeedService      $feedService  Feed-render service.
     * @param IURLGenerator    $urlGenerator Absolute-URL builder for
     *                                       feed URLs returned to
     *                                       the management
     *                                       endpoints.
     * @param string|null      $userId       The calling user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly FeedTokenService $tokenService,
        private readonly FeedService $feedService,
        private readonly IURLGenerator $urlGenerator,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * `GET /api/feed/token` — issue or return the caller's active token
     * (REQ-FEED-001).
     *
     * @return DataResponse `{token, url}` envelope on success;
     *                      `{error}` 401 when not authenticated.
     */
    #[NoAdminRequired]
    public function getToken(): DataResponse
    {
        if ($this->userId === null) {
            return new DataResponse(
                data: ['error' => 'Not logged in'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        $token = $this->tokenService->getOrCreateToken(userId: $this->userId);
        return $this->buildTokenResponse(
            token: $token,
            statusCode: Http::STATUS_OK
        );
    }//end getToken()

    /**
     * `POST /api/feed/token/regenerate` — atomically revoke the caller's
     * existing token (if any) and issue a fresh one (REQ-FEED-002).
     *
     * @return DataResponse `{token, url}` envelope on success;
     *                      `{error}` 401 when not authenticated.
     */
    #[NoAdminRequired]
    public function regenerateToken(): DataResponse
    {
        if ($this->userId === null) {
            return new DataResponse(
                data: ['error' => 'Not logged in'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        $token = $this->tokenService->regenerateToken(userId: $this->userId);
        return $this->buildTokenResponse(
            token: $token,
            statusCode: Http::STATUS_OK
        );
    }//end regenerateToken()

    /**
     * `DELETE /api/feed/token` — soft-revoke the caller's active token.
     * Idempotent (REQ-FEED-003).
     *
     * @return DataResponse Empty 204 on success; 401 when not authenticated.
     */
    #[NoAdminRequired]
    public function revokeToken(): DataResponse
    {
        if ($this->userId === null) {
            return new DataResponse(
                data: ['error' => 'Not logged in'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        $this->tokenService->revokeToken(userId: $this->userId);
        return new DataResponse(
            data: [],
            statusCode: Http::STATUS_NO_CONTENT
        );
    }//end revokeToken()

    /**
     * `GET /feed/{token}.xml` — public feed-render endpoint
     * (REQ-FEED-004..007).
     *
     * Serves RSS 2.0 by default; honours `Accept: application/atom+xml`
     * and the `?format=atom` fallback (design D4). Invalid, unknown,
     * AND revoked tokens all yield a uniform HTTP 404 — never a 403 —
     * so an attacker cannot probe for token existence (REQ-FEED-004
     * scenarios "Fetch feed with invalid token" / "Fetch feed with
     * revoked token").
     *
     * @param string $token The opaque token from the URL path.
     *
     * @return DataDisplayResponse The serialised XML feed or a 404
     *                             stub.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function publicFeed(string $token): DataDisplayResponse
    {
        $resolved = $this->tokenService->resolveToken(token: $token);
        if ($resolved === null) {
            return new DataDisplayResponse(
                data: '',
                statusCode: Http::STATUS_NOT_FOUND,
                headers: ['Content-Type' => 'text/plain; charset=utf-8']
            );
        }

        $format = $this->resolveRequestedFormat();
        try {
            $body = $this->feedService->renderFeed(
                token: $resolved,
                format: $format
            );
        } catch (Throwable) {
            // Render failures degrade to 404 rather than leaking stack
            // traces or partial XML to anonymous callers.
            return new DataDisplayResponse(
                data: '',
                statusCode: Http::STATUS_NOT_FOUND,
                headers: ['Content-Type' => 'text/plain; charset=utf-8']
            );
        }

        $mime = FeedService::MIME_RSS;
        if ($format === FeedService::FORMAT_ATOM) {
            $mime = FeedService::MIME_ATOM;
        }

        return new DataDisplayResponse(
            data: $body,
            statusCode: Http::STATUS_OK,
            headers: [
                'Content-Type'  => $mime,
                'Cache-Control' => 'private, max-age=300',
            ]
        );
    }//end publicFeed()

    /**
     * Build the `{token, url}` envelope returned to the management
     * endpoints. Centralises the URL construction so REQ-FEED-001 and
     * REQ-FEED-002 cannot drift out of sync.
     *
     * @param FeedToken $token      The active feed token.
     * @param int       $statusCode The HTTP status code.
     *
     * @return DataResponse The envelope.
     */
    private function buildTokenResponse(
        FeedToken $token,
        int $statusCode
    ): DataResponse {
        $url = $this->urlGenerator->linkToRouteAbsolute(
            routeName: Application::APP_ID.'.feed.publicFeed',
            arguments: ['token' => (string) $token->getToken()]
        );

        return new DataResponse(
            data: [
                'token' => (string) $token->getToken(),
                'url'   => $url,
            ],
            statusCode: $statusCode
        );
    }//end buildTokenResponse()

    /**
     * Resolve the requested feed format from the `Accept` header (with
     * a `?format=atom` query-string fallback for clients that cannot
     * set headers — design D4).
     *
     * @return string Either {@see FeedService::FORMAT_RSS} or
     *                {@see FeedService::FORMAT_ATOM}.
     */
    private function resolveRequestedFormat(): string
    {
        $explicit = (string) $this->request->getParam(
            key: 'format',
            default: ''
        );
        if (strtolower(string: $explicit) === FeedService::FORMAT_ATOM) {
            return FeedService::FORMAT_ATOM;
        }

        $accept = (string) $this->request->getHeader(name: 'Accept');
        if (str_contains(haystack: strtolower(string: $accept), needle: 'application/atom+xml') === true) {
            return FeedService::FORMAT_ATOM;
        }

        return FeedService::FORMAT_RSS;
    }//end resolveRequestedFormat()
}//end class
