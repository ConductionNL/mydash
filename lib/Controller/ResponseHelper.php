<?php

/**
 * ResponseHelper
 *
 * Helper for building common JSON responses in controllers.
 *
 * @category  Controller
 * @package   OCA\MyDash\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;

/**
 * Helper for building common JSON responses in controllers.
 */
class ResponseHelper
{

    /**
     * The localization service instance.
     *
     * @var IL10N|null
     */
    private static ?IL10N $l10n = null;

    /**
     * Set the IL10N instance for translating user-facing messages.
     *
     * @param IL10N $l10n The localization service.
     *
     * @return void
     */
    public static function setL10N(IL10N $l10n): void
    {
        self::$l10n = $l10n;
    }//end setL10N()

    /**
     * Translate a string if the IL10N service is available.
     *
     * @param string $text The text to translate.
     *
     * @return string The translated text.
     */
    private static function translate(string $text): string
    {
        if (self::$l10n !== null) {
            return self::$l10n->t($text);
        }

        return $text;
    }//end translate()

    /**
     * Create an unauthorized response.
     *
     * @return JSONResponse The unauthorized response.
     */
    public static function unauthorized(): JSONResponse
    {
        return new JSONResponse(
            data: ['error' => self::translate('Not logged in')],
            statusCode: Http::STATUS_UNAUTHORIZED
        );
    }//end unauthorized()

    /**
     * Create a forbidden response.
     *
     * @param string|null $message The error message.
     *
     * @return JSONResponse The forbidden response.
     */
    public static function forbidden(
        ?string $message=null
    ): JSONResponse {
        return new JSONResponse(
            data: ['error' => $message ?? self::translate('Access denied')],
            statusCode: Http::STATUS_FORBIDDEN
        );
    }//end forbidden()

    /**
     * Create an error response from an exception.
     *
     * @param \Exception $exception  The exception.
     * @param int        $statusCode The HTTP status code.
     *
     * @return JSONResponse The error response.
     */
    public static function error(
        \Exception $exception,
        int $statusCode=Http::STATUS_BAD_REQUEST
    ): JSONResponse {
        return new JSONResponse(
            data: ['error' => $exception->getMessage()],
            statusCode: $statusCode
        );
    }//end error()

    /**
     * Create a success response.
     *
     * @param array $data       The response data.
     * @param int   $statusCode The HTTP status code.
     *
     * @return JSONResponse The success response.
     */
    public static function success(
        array $data,
        int $statusCode=Http::STATUS_OK
    ): JSONResponse {
        return new JSONResponse(
            data: $data,
            statusCode: $statusCode
        );
    }//end success()

    /**
     * Serialize an array of entities.
     *
     * @param array $entities The entities to serialize.
     *
     * @return array The serialized entities.
     */
    public static function serializeList(array $entities): array
    {
        $serialized = [];
        foreach ($entities as $entity) {
            $serialized[] = $entity->jsonSerialize();
        }

        return $serialized;
    }//end serializeList()
}//end class
