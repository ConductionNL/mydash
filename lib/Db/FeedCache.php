<?php

/**
 * FeedCache Entity
 *
 * Represents one cached external RSS / Atom feed (REQ-FRJ-001..012). One
 * row per distinct `feedUrl`, holding the conditional GET headers (ETag,
 * Last-Modified), the fetch metadata (lastFetchedAt, lastSuccessAt,
 * lastFailureReason), and the normalised items as a JSON-encoded string
 * (capped at 50 items, see {@see self::encodeItems()}).
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 EUPL-1.2
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Feed-cache entity (REQ-FRJ-001..012).
 *
 * @method string|null getFeedUrl()
 * @method void setFeedUrl(?string $feedUrl)
 * @method string|null getLastFetchedAt()
 * @method void setLastFetchedAt(?string $lastFetchedAt)
 * @method string|null getLastSuccessAt()
 * @method void setLastSuccessAt(?string $lastSuccessAt)
 * @method string|null getLastFailureReason()
 * @method void setLastFailureReason(?string $lastFailureReason)
 * @method string|null getEtag()
 * @method void setEtag(?string $etag)
 * @method string|null getLastModified()
 * @method void setLastModified(?string $lastModified)
 * @method string|null getItemsJson()
 * @method void setItemsJson(?string $itemsJson)
 */
class FeedCache extends Entity implements JsonSerializable
{
    /**
     * Maximum number of cached items per feed (REQ-FRJ-005).
     *
     * @var integer
     */
    public const MAX_ITEMS = 50;

    /**
     * The remote feed URL — UNIQUE across the table.
     *
     * @var string|null
     */
    protected ?string $feedUrl = null;

    /**
     * The last fetch attempt timestamp; set on every tick (including
     * 304s and failures) so the orphan-cleanup job can age out feeds
     * that are no longer referenced.
     *
     * @var string|null
     */
    protected ?string $lastFetchedAt = null;

    /**
     * The last successful fetch timestamp; only updated on 2xx (and 304
     * — see REQ-FRJ-004 "items untouched, only lastFetchedAt updated"
     * which is implemented by NOT updating lastSuccessAt on 304 unless
     * the prior cache row was empty). Failures (timeout, 4xx, 5xx, parse
     * error) leave it untouched.
     *
     * @var string|null
     */
    protected ?string $lastSuccessAt = null;

    /**
     * The last failure reason — either a transport error
     * (`"timeout: ..."`), an HTTP error (`"410 Gone"`), a parse error
     * (`"parse error: ..."`), an allow-list reject
     * (`"host not in allow-list"`), or a size-cap reject
     * (`"response too large"`). Null on the first row insert and after
     * any successful fetch.
     *
     * @var string|null
     */
    protected ?string $lastFailureReason = null;

    /**
     * The most recent `ETag` response header value — used for
     * `If-None-Match` on the next conditional GET (REQ-FRJ-004).
     *
     * @var string|null
     */
    protected ?string $etag = null;

    /**
     * The most recent `Last-Modified` response header value — used for
     * `If-Modified-Since` on the next conditional GET (REQ-FRJ-004).
     *
     * @var string|null
     */
    protected ?string $lastModified = null;

    /**
     * The cached normalised items as a JSON-encoded string. Null until
     * the first successful fetch. Capped at {@see self::MAX_ITEMS} items
     * via {@see self::encodeItems()}.
     *
     * @var string|null
     */
    protected ?string $itemsJson = null;

    /**
     * Constructor — registers column types so the auto-increment id
     * hydrates as int rather than string.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
    }//end __construct()

    /**
     * Decode the persisted items JSON.
     *
     * @return array<int, array<string, mixed>> The items, or an empty
     *                                          array when null/invalid.
     */
    public function decodeItems(): array
    {
        if ($this->itemsJson === null || $this->itemsJson === '') {
            return [];
        }

        $decoded = json_decode(
            json: $this->itemsJson,
            associative: true
        );

        if (is_array(value: $decoded) === false) {
            return [];
        }

        return $decoded;
    }//end decodeItems()

    /**
     * JSON-encode and persist a list of normalised items, capping at
     * {@see self::MAX_ITEMS} entries (REQ-FRJ-005).
     *
     * Items MUST already be sorted newest-first by the caller; this
     * method does NOT sort. The cap takes the leading slice (assumed to
     * be the newest entries).
     *
     * @param array<int, array<string, mixed>> $items The items.
     *
     * @return void
     */
    public function encodeItems(array $items): void
    {
        if (count(value: $items) > self::MAX_ITEMS) {
            $items = array_slice(
                array: $items,
                offset: 0,
                length: self::MAX_ITEMS
            );
        }

        $encoded = json_encode(
            value: $items,
            flags: (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($encoded === false) {
            $this->setItemsJson(null);
        } else {
            $this->setItemsJson($encoded);
        }
    }//end encodeItems()

    /**
     * Serialize to JSON for diagnostics / admin UI.
     *
     * The cached items are emitted as a decoded array so the admin UI
     * does not need to re-decode them on the client side.
     *
     * @return array The serialized feed-cache row.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->getId(),
            'feedUrl'           => $this->feedUrl,
            'lastFetchedAt'     => $this->lastFetchedAt,
            'lastSuccessAt'     => $this->lastSuccessAt,
            'lastFailureReason' => $this->lastFailureReason,
            'etag'              => $this->etag,
            'lastModified'      => $this->lastModified,
            'items'             => $this->decodeItems(),
        ];
    }//end jsonSerialize()
}//end class
